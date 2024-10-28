<?php

namespace ginvoicing\helpers;

use ginvoicing\Pdf;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Command
 *
 * This class represents a shell command.
 *
 * Its meant for exuting a single command and capturing stdout and stderr.
 *
 * Example:
 *
 * ```
 * $command = new Command('/usr/local/bin/mycommand -a -b');
 * $command->addArg('--name=', "d'Artagnan");
 * if ($command->execute()) {
 *     echo $command->getOutput();
 * } else {
 *     echo $command->getError();
 *     $exitCode = $command->getExitCode();
 * }
 * ```
 *
 * @author Tarun Jangra <tarun.jangra@hotmail.com>
 * @license http://www.opensource.org/licenses/MIT
 */
class Command
{
    /**
     * @var bool whether to escape any argument passed through `addArg()`.
     * Default is `true`.
     */
    public bool $escapeArgs = false;

    /**
     * @var bool whether to escape the command passed to `setCommand()` or the
     * constructor.  This is only useful if `$escapeArgs` is `false`. Default
     * is `false`.
     */
    public bool $escapeCommand = false;


    /**
     * @var int the time in seconds after which a command should be terminated.
     * This only works in non-blocking mode. Default is `null` which means the
     * process is never terminated.
     */
    public int $timeout = 5;

    /**
     * @var null|string the locale to temporarily set before calling
     * `escapeshellargs()`. Default is `null` for none.
     */
    public string|null $locale = null;

    /**
     * @var null|string to pipe to standard input
     */
    protected string|null $_stdIn = null;

    /**
     * @var string the command to execute
     */
    protected string $_command;

    /**
     * @var array the list of command arguments
     */
    protected array $_args = array();

    /**
     * @var string the stdout output
     */
    protected string $_stdOut = '';

    /**
     * @var string the stderr output
     */
    protected string $_stdErr = '';

    /**
     * @var int the exit code
     */
    protected int $_exitCode;

    /**
     * @var string the error message
     */
    protected string $_error = '';

    /**
     * @var bool whether the command was successfully executed
     */
    protected bool $_executed = false;

    protected string|null $htmlURL = null;

    public string $outputFile;

    /**
     * @param string|array $options either a command string or an options array
     * @see setOptions
     */
    public function __construct($options = null)
    {
        if (is_array($options)) {
            $this->setOptions($options);
        } elseif (is_string($options)) {
            $this->setCommand($options);
        }
    }

    /**
     * @param array $options array of name => value options (i.e. public
     * properties) that should be applied to this object. You can also pass
     * options that use a setter, e.g. you can pass a `fileName` option which
     * will be passed to `setFileName()`.
     * @return static for method chaining
     * @throws \Exception on unknown option keys
     */
    public function setOptions(array $options): Command
    {
        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            } else {
                $method = 'set' . ucfirst($key);
                if (method_exists($this, $method)) {
                    call_user_func(array($this, $method), $value);
                } else {
                    throw new \Exception("Unknown configuration option '$key'");
                }
            }
        }
        return $this;
    }

    /**
     * @param string $command the command or full command string to execute,
     * like 'gzip' or 'gzip -d'.  You can still call addArg() to add more
     * arguments to the command. If `$escapeCommand` was set to true, the command
     * gets escaped with `escapeshellcmd()`.
     * @return static for method chaining
     */
    public function setCommand(string $command): Command
    {
        if ($this->escapeCommand) {
            $command = escapeshellcmd($command);
        }
        if ($this->getIsWindows()) {
            // Make sure to switch to correct drive like "E:" first if we have
            // a full path in command
            if (isset($command[1]) && $command[1] === ':') {
                $position = 1;
                // Could be a quoted absolute path because of spaces.
                // i.e. "C:\Program Files (x86)\file.exe"
            } elseif (isset($command[2]) && $command[2] === ':') {
                $position = 2;
            } else {
                $position = false;
            }

            // Absolute path. If it's a relative path, let it slide.
            if ($position) {
                $command = sprintf(
                    $command[$position - 1] . ': && cd %s && %s',
                    escapeshellarg(dirname($command)),
                    escapeshellarg(basename($command))
                );
            }
        }
        $this->_command = $command;
        return $this;
    }

    /**
     * @param string|resource $stdIn If set, the string will be piped to the
     * command via standard input. This enables the same functionality as
     * piping on the command line. It can also be a resource like a file
     * handle or a stream in which case its content will be piped into the
     * command like an input redirection.
     * @return static for method chaining
     */
    public function setStdIn(string $stdIn): Command
    {
        $this->_stdIn = $stdIn;
        return $this;
    }

    /**
     * @return string|null the command that was set through `setCommand()` or
     * passed to the constructor. `null` if none.
     */
    public function getCommand(): string|null
    {
        return $this->_command;
    }

    /**
     * @return string|bool the full command string to execute. If no command
     * was set with `setCommand()` or passed to the constructor it will return
     * `false`.
     */
    public function getExecCommand(): string|bool
    {
        $command = $this->getCommand();
        if (!$command) {
            $this->_error = 'Could not locate any executable command';
            return false;
        }

        $args = $this->getArgs();
        return $args ? $command . ' ' . $args : $command;
    }


    /**
     * @param string $args the command arguments as string like `'--arg1=value1
     * --arg2=value2'`. Note that this string will not get escaped. This will
     * overwrite the args added with `addArgs()`.
     * @return static for method chaining
     */
    public function setArgs(string $args): Command
    {
        $this->_args = array($args);
        return $this;
    }

    /**
     * @return string the command args that where set with `setArgs()` or added
     * with `addArg()` separated by spaces.
     */
    public function getArgs(): array
    {
        return $this->_args;
    }

    /**
     * @param string $key the argument key to add e.g. `--feature` or
     * `--name=`. If the key does not end with `=`, the (optional) $value will
     * be separated by a space. The key will get escaped if `$escapeArgs` is `true`.
     * @param string|array|null $value the optional argument value which will
     * get escaped if $escapeArgs is true.  An array can be passed to add more
     * than one value for a key, e.g.
     * `addArg('--exclude', array('val1','val2'))`
     * which will create the option
     * `'--exclude' 'val1' 'val2'`.
     * @param bool|null $escape if set, this overrides the `$escapeArgs` setting
     * and enforces escaping/no escaping of keys and values
     * @return static for method chaining
     */
    public function addArg(string $key, mixed $value = null, bool|null $escape = null): Command
    {
        $doEscape = $escape !== null ? $escape : $this->escapeArgs;
        $useLocale = $doEscape && $this->locale !== null;

        if ($useLocale) {
            $locale = setlocale(LC_CTYPE, 0);   // Returns current locale setting
            setlocale(LC_CTYPE, $this->locale);
        }
        if ($value === null) {
            $this->_args[] = $doEscape ? escapeshellarg($key) : $key;
        } else {
            if (substr($key, -1) === '=') {
                $separator = '=';
                $argKey = substr($key, 0, -1);
            } else {
                $separator = ' ';
                $argKey = $key;
            }
            $argKey = $doEscape ? escapeshellarg($argKey) : $argKey;

            if (is_array($value)) {
                $params = array();
                foreach ($value as $v) {
                    $params[] = $doEscape ? escapeshellarg($v) : $v;
                }
                $this->_args[] = $argKey . $separator . implode(' ', $params);
            } else {
                $this->_args[] = $argKey . $separator .
                    ($doEscape ? escapeshellarg($value) : $value);
            }
        }
        if ($useLocale) {
            setlocale(LC_CTYPE, $locale);
        }

        return $this;
    }

    /**
     * @param bool $trim whether to `trim()` the return value. The default is `true`.
     * @param string $characters the list of characters to trim. The default
     * is ` \t\n\r\0\v\f`.
     * @return string the command output (stdout). Empty if none.
     */
    public function getOutput(bool $trim = true, string $characters = " \t\n\r\0\v\f"): string
    {
        return $trim ? trim($this->_stdOut, $characters) : $this->_stdOut;
    }

    /**
     * @param bool $trim whether to `trim()` the return value. The default is `true`.
     * @param string $characters the list of characters to trim. The default
     * is ` \t\n\r\0\v\f`.
     * @return string the error message, either stderr or an internal message.
     * Empty string if none.
     */
    public function getError(bool $trim = true, string $characters = " \t\n\r\0\v\f"): string
    {
        return $trim ? trim($this->_error, $characters) : $this->_error;
    }

    /**
     * @param bool $trim whether to `trim()` the return value. The default is `true`.
     * @param string $characters the list of characters to trim. The default
     * is ` \t\n\r\0\v\f`.
     * @return string the stderr output. Empty if none.
     */
    public function getStdErr(bool $trim = true, string $characters = " \t\n\r\0\v\f"): string
    {
        return $trim ? trim($this->_stdErr, $characters) : $this->_stdErr;
    }

    /**
     * @return int|null the exit code or null if command was not executed yet
     */
    public function getExitCode(): int|null
    {
        return $this->_exitCode;
    }

//    /**
//     * @return string whether the command was successfully executed
//     */
//    public function getExecuted(): string
//    {
//        return $this->_executed;
//    }

    public function addArgs($args): void
    {
        if (isset($args['input'])) {
            // Typecasts TmpFile to filename
            $this->addArg((string)$args['input']);
            unset($args['input']);
        }
        if (isset($args['inputArg'])) {
            if (filter_var($args['inputArg'], FILTER_VALIDATE_URL)) {
                $this->htmlURL = $args['inputArg'];
            } else {
                // Typecasts TmpFile to filename and escapes argument
                $this->addArg((string)$args['inputArg'], null, true);
            }
            unset($args['inputArg']);
        }
        foreach ($args as $key => $val) {
            if (is_numeric($key)) {
                $this->addArg("--$val");
            } elseif (is_array($val)) {
                foreach ($val as $vkey => $vval) {
                    if (is_int($vkey)) {
                        $this->addArg("--$key", $vval);
                    } else {
                        $this->addArg("--$key", array($vkey, $vval));
                    }
                }
            } else {
                $this->addArg("--$key", $val);
            }
        }
    }

    /**
     * Execute the command
     *
     * @return bool whether execution was successful. If `false`, error details
     * can be obtained from `getError()`, `getStdErr()` and `getExitCode()`.
     */
    public function execute(): bool
    {

        if ($this->htmlURL !== null && filter_var($this->htmlURL, FILTER_VALIDATE_URL)) {
            $response = HttpClient::create()->request('POST', $this->_command . '/pdf-from-url', [
                'json' => ['url' => $this->htmlURL, 'options' => implode(' ', $this->getArgs())],
            ]);
        } else {
            $response = HttpClient::create()->request('POST', $this->_command . '/pdf-from-html', [
                'json' => ['html_content' => $this->htmlURL, 'options' => implode(' ', $this->getArgs())],
            ]);
        }


        if ($response->getStatusCode() !== 200) {
            // log some error.
            return false;
        }

        file_put_contents($this->outputFile, $response->getContent());

        $this->_executed = true;

        return true;
    }

    /**
     * @return bool whether we are on a Windows OS
     */
    public function getIsWindows(): bool
    {
        return strncasecmp(PHP_OS, 'WIN', 3) === 0;
    }

    /**
     * @return string the current command string to execute
     */
    public function __toString(): string
    {
        return (string)$this->getExecCommand();
    }
}
