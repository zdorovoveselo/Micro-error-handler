<?php
/**
 * PHP error handler and debugger.
 *
 * @package   Peraleks\ErrorHandler
 * @copyright 2017 Aleksey Perevoshchikov <aleksey.perevoshchikov.n@gmail.com>
 * @license   https://github.com/peraleks/error-handler/blob/master/LICENSE.md MIT
 * @link      https://github.com/peraleks/error-handler
 */

declare(strict_types=1);

namespace Peraleks\ErrorHandler\Core;

/**
 * Class ErrorHandler
 *
 * Является контроллером обработки ошибок.
 * Регистрирует функции error_handler, exception_handler, shutdown_function.
 * Инстанцирует помощника Helper и передаёт ему ошибки для дальнейшей обработки.
 * Производит отложенный вывод ошибок, и запуск пользовательских
 * функций обратного вызова в shutdown().
 */
class ErrorHandler
{
    /**
     * Текущая версия пакета.
     */
    const VERSION = '0.9.2';

    /**
     * Singleton.
     *
     * @var ErrorHandler
     */
    static private $instance;

    /**
     * Помощник (вторая часть контроллера).
     *
     * @var Helper
     */
    private $helper;

    /**
     * Путь к файлу конфигурации.
     *
     * @var string
     */
    private $configFile;

    /**
     * Данные ошибок для отложенного вывода.
     *
     * @var array
     */
    private $callbackData = [];

    /**
     * Callbacks для отложенной обработки и вывода ошибок.
     *
     * @var array
     */
    private $errorCallbacks = [];

    /**
     * Пользовательские Callbacks
     *
     * @var array
     */
    private $userCallbacks = [];

    /**
     * Последняя ошибка.
     *
     * @var \Throwable
     */
    private $lastError;

    /**
     * ErrorHandler constructor.
     *
     * Регистрирует функции-обработчики ошибок.
     *
     * @param null $configFile
     */
    private function __construct($configFile = null)
    {
        ini_set('display_errors', 'Off');
        set_error_handler([$this, 'error']);
        set_exception_handler([$this, 'exception']);
        register_shutdown_function([$this, 'shutdown']);
        $this->configFile = $configFile;
    }

    /**
     * Singleton.
     *
     * @param null | string  $configFile  полное имя файла конфигурации
     * @return ErrorHandler
     */
    public static function instance($configFile = null)
    {
        return self::$instance ?? self::$instance = new self($configFile);
    }

    /**
     * Обработчик ошибок.
     *
     * Конвертирует полученную ошибку в объект исключения
     * и передаёт в обработчик исключений.
     *
     * @param int    $code    код уровня ошибки
     * @param string $message сообщение ошибки
     * @param string $file    файл, где произошла ошибка
     * @param int    $line    строка ошибки
     * @return bool           true
     */
    public function error($code, $message, $file, $line)
    {
        $this->exception(new \ErrorException($message, $code, $code, $file, $line), '', 'error');
        return true;
    }

    /**
     * Обработчик исключений.
     *
     * Инстанцирует помощника (Helper) и передаёт ему объект ошибки
     * для дальнейшей обработки.
     * <br>
     * Если вторым параметром ($logType) передана непустая строка,
     * то она будет использована как тип ошибки.
     * Это означает, что такая ошибка предназначена только для логирования
     * и будет проигнорирована уведомителями, у которых в конфигурации
     * присутствует параметр 'ignoreLogType' => true.
     * По умолчанию данный параметр задан только у ServerErrorNotifier.
     * Тоесть, если вы поймали исключение в try {} catch () и хотите
     * продожить скрипт, а ошибку записать в лог, выполните:
     * <br>
     * ErrorHandler::instance()->exception($e, 'someType').
     * <br>
     * Если не передать второй параметр, ServerErrorNotifier отправит
     * заголовок "500", покажет страницу ошибки и прервёт вполнение скрипта.
     *
     * @param \Throwable $e       объект ошибки
     * @param string     $logType тип ошибки
     * @param string     $handler название функции обработчика ('error' | 'exception' | 'shutdown')
     */
    public function exception(\Throwable $e, $logType = '', string $handler = 'exception')
    {
        $this->lastError = $e;
        if (!$this->helper) {
            $this->helper = new Helper($this->configFile, $this);
            $this->helper->createConfigObject();
        }
        $this->helper->handle($e, $logType, $handler);

    }

    /**
     * Shutdown function.
     *
     * Вылавливает из буфера последнюю фатальную ошибку,
     * конвертирует в исключение и передаёт в обработчик исключений
     * Инициирует выполнение пользовательских callbacks,
     * и callbacks отложенного вывода ошибок.
     */
    public function shutdown()
    {
        if ($el = error_get_last()) {
            $e = new \ErrorException($el['message'], $el['type'], $el['type'], $el['file'], $el['line']);

            /* передаём внутренние фатальные ошибки в отдельный обработчик,
             * для вывода и логирования, также предварительно передаём последнюю ошибку,
             * чтобы не потерять её, так как скорее всего она не была обработана
             * из-за фатальной ошибки */
            if ($this->helper && $this->helper->getInnerShutdownFatal()) {
                $this->helper->exception($this->lastError);
                $this->helper->exception($e);
            } else {
                $this->exception($e, '', 'shutdown');
            }
        }
        if ($this->userCallbacks) {
            $this->invokeCallbacks($this, $this->userCallbacks);
        }
        $this->invokeDeferred();
    }

    /**
     * Выводит все саккумулированные за время выполнения ошибки.
     *
     * Если используется fastcgi_finish_request() для завершения
     * обработки запроса, можно предварительно вызвать данный метод
     * для вывода в браузер информации из errorCallbacks.
     */
    public function invokeDeferred()
    {
        if ($this->errorCallbacks) {
            $this->invokeCallbacks($this->helper, $this->errorCallbacks, $this->callbackData);
        }
    }

    /**
     * Выполняет пользовательские callbacks и callbacks отложенного уведомления об ошибках.
     *
     * Так как обработчики зарегистрированные в ErrorHandler не работают
     * в стеке выше shutdown function, для безопасного выполнения callbacks регистрируется
     * новый обработчик ошибок (Helper), исключения тоже перенаправляются в новый обработчик.
     *
     * @param ErrorHandler|Helper $handlerObj
     * @param array               $callbacks  callbacks
     * @param null|array          $data       саккумулированные данные ошибок
     */
    private function invokeCallbacks($handlerObj, array $callbacks, $data = null)
    {
        foreach ($callbacks as $callback) {
            try {
                set_error_handler([$handlerObj, 'error']);

                call_user_func($callback, $data);

            } catch (\Throwable $e) {
                $handlerObj->exception($e);
            } finally {
                restore_error_handler();
            }
        }
    }

    /**
     * Сохраняет данные ошибок в двумерный массив [$key][0 => $value]
     *
     * Данные будут переданы в callback в shutdown function.
     * Ключи $key лучше задавать по названию класса уведомителя,
     * используя переменную __CLASS__
     *
     * @param string $key
     * @param mixed  $value данные ошибок
     */
    public function addErrorCallbackData(string $key, $value)
    {
        $this->callbackData[$key][] = $value;
    }

    /**
     * Регистрирует callback функцию для отложенной обработки ошибок.
     *
     * Метод будет вызван в shutdown function. Аргументом будет передан
     * массив данных, сохранённых при помощи addErrorCallbackData()
     *
     * @param callable $callback функция для обработки и вывода ошибок
     */
    public function addErrorCallback(callable $callback)
    {
        $this->errorCallbacks[] = $callback;
    }

    /**
     * Регистрирует пользовательский callback, чтобы
     * позже он был выполен в shutdown function.
     * Фатальные ошибки в этом callback не могут быть
     * обработаны.
     *
     * @param callable $callback
     */
    public function addUserCallback(callable $callback)
    {
        $this->userCallbacks[] = $callback;
    }
}

