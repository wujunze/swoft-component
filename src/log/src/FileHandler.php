<?php

namespace Swoft\Log;

use Monolog\Handler\AbstractProcessingHandler;
use Swoft\App;
use \InvalidArgumentException;
/**
 * 日志文件输出器
 *
 * @uses      FileHandler
 * @version   2017年07月05日
 * @author    stelin <phpcrazy@126.com>
 * @copyright Copyright 2010-2018 Swoft software
 * @license   PHP Version 7.x {@link http://www.php.net/license/3_0.txt}
 */
class FileHandler extends AbstractProcessingHandler
{
    /**
     * @var array 输出包含日志级别集合
     */
    protected $levels = [];

    /**
     * @var string 输入日志文件名称
     */
    protected $logFile = "";

    /**
     * @var int  The maximal amount of files to keep (0 means unlimited)
     */
    protected $maxFiles =  5;

    protected $mustRotate;

    protected $nextRotation;

    protected $filenameFormat;

    protected $dateFormat;

    /**
     * @param string   $filename
     * @param int      $maxFiles       The maximal amount of files to keep (0 means unlimited)
     * @param int      $level          The minimum logging level at which this handler will be triggered
     * @param Boolean  $bubble         Whether the messages that are handled can bubble up the stack or not
     * @param int|null $filePermission Optional file permissions (default (0644) are only for owner read/write)
     * @param Boolean  $useLocking     Try to lock log file before doing any writes
     *
     * @throws
     */
    public function __construct($filename, $maxFiles = 0, $level = Logger::DEBUG, $bubble = true, $filePermission = null, $useLocking = false)
    {
        $this->logFile = $filename;
        $this->maxFiles = (int) $maxFiles;
        $this->nextRotation = new \DateTimeImmutable('tomorrow');
        $this->filenameFormat = '{filename}-{date}';
        $this->dateFormat = 'Y-m-d';

        parent::__construct($level, $bubble);
    }



    /**
     * 批量输出日志
     *
     * @param array $records 日志记录集合
     *
     * @return bool
     */
    public function handleBatch(array $records)
    {
        $records = $this->recordFilter($records);
        if (empty($records)) {
            return true;
        }

        $lines = array_column($records, 'formatted');

        $this->write($lines);
    }

    /**
     * 输出到文件
     *
     * @param array $records 日志记录集合
     */
    protected function write(array $records)
    {

        // on the first record written, if the log is new, we should rotate (once per day)
        if (null === $this->mustRotate) {
            $this->mustRotate = !file_exists($this->logFile);
        }

        if ($this->nextRotation < $records['datetime']) {
            $this->mustRotate = true;
            $this->close();
        }


        // 参数
        //$this->createDir();
        $isTask = App::isWorkerStatus();
        $logFile = App::getAlias($this->logFile);
        $messageText = implode("\n", $records) . "\n";

        // 同步写
        if ($isTask === false) {
            return $this->syncWrite($logFile, $messageText);
        }
        // 异步写
        $this->asyncWrite($logFile, $messageText);
    }

    /**
     * 同步写文件
     *
     * @param string $logFile     日志路径
     * @param string $messageText 文本信息
     */
    private function syncWrite(string $logFile, string $messageText)
    {
        $fp = fopen($logFile, 'a');
        if ($fp === false) {
            throw new \InvalidArgumentException("Unable to append to log file: {$this->logFile}");
        }
        flock($fp, LOCK_EX);
        fwrite($fp, $messageText);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * 异步写文件
     *
     * @param string $logFile     日志路径
     * @param string $messageText 文本信息
     */
    private function asyncWrite(string $logFile, string $messageText)
    {
        while (true) {
            $result = \Swoole\Async::writeFile($logFile, $messageText, null, FILE_APPEND);
            if ($result == true) {
                break;
            }
        }
    }

    /**
     * 记录过滤器
     *
     * @param array $records 日志记录集合
     *
     * @return array
     */
    private function recordFilter(array $records)
    {
        $messages = [];
        foreach ($records as $record) {
            if (!isset($record['level'])) {
                continue;
            }
            if (!$this->isHandling($record)) {
                continue;
            }

            $record = $this->processRecord($record);
            $record['formatted'] = $this->getFormatter()->format($record);

            $messages[] = $record;
        }
        return $messages;
    }



    /**
     * check是否输出日志
     *
     * @param array $record
     *
     * @return bool
     */
    public function isHandling(array $record)
    {
        if (empty($this->levels)) {
            return true;
        }

        return in_array($record['level'], $this->levels);
    }

    /**
     * Rotates the files.
     */
    protected function rotate()
    {
        // update filename
        $this->logFile = $this->getTimedFilename();
        $this->nextRotation = new \DateTimeImmutable('tomorrow');

        // skip GC of old logs if files are unlimited
        if (0 === $this->maxFiles) {
            return;
        }

        $logFiles = glob($this->getGlobPattern());
        if ($this->maxFiles >= count($logFiles)) {
            // no files to remove
            return;
        }

        // Sorting the files by name to remove the older ones
        usort($logFiles, function ($a, $b) {
            return strcmp($b, $a);
        });

        foreach (array_slice($logFiles, $this->maxFiles) as $file) {
            if (is_writable($file)) {
                // suppress errors here as unlink() might fail if two processes
                // are cleaning up/rotating at the same time
                set_error_handler(function ($errno, $errstr, $errfile, $errline) {
                });
                unlink($file);
                restore_error_handler();
            }
        }

        $this->mustRotate = false;
    }

    protected function getTimedFilename()
    {
        $fileInfo = pathinfo($this->logFile);
        $timedFilename = str_replace(
            ['{filename}', '{date}'],
            [$fileInfo['filename'], date($this->dateFormat)],
            $fileInfo['dirname'] . '/' . $this->filenameFormat
        );

        if (!empty($fileInfo['extension'])) {
            $timedFilename .= '.'.$fileInfo['extension'];
        }

        return $timedFilename;
    }

    protected function getGlobPattern()
    {
        $fileInfo = pathinfo($this->logFile);
        $glob = str_replace(
            ['{filename}', '{date}'],
            [$fileInfo['filename'], '*'],
            $fileInfo['dirname'] . '/' . $this->filenameFormat
        );
        if (!empty($fileInfo['extension'])) {
            $glob .= '.'.$fileInfo['extension'];
        }

        return $glob;
    }


    public function close()
    {
        parent::close();

        if (true === $this->mustRotate) {
            $this->rotate();
        }
    }

    public function setFilenameFormat($filenameFormat, $dateFormat)
    {
        if (!preg_match('{^Y(([/_.-]?m)([/_.-]?d)?)?$}', $dateFormat)) {
            throw new InvalidArgumentException(
                'Invalid date format - format must be one of '.
                'RotatingFileHandler::FILE_PER_DAY ("Y-m-d"), RotatingFileHandler::FILE_PER_MONTH ("Y-m") '.
                'or RotatingFileHandler::FILE_PER_YEAR ("Y"), or you can set one of the '.
                'date formats using slashes, underscores and/or dots instead of dashes.'
            );
        }
        if (substr_count($filenameFormat, '{date}') === 0) {
            throw new InvalidArgumentException(
                'Invalid filename format - format must contain at least `{date}`, because otherwise rotating is impossible.'
            );
        }
        $this->filenameFormat = $filenameFormat;
        $this->dateFormat = $dateFormat;
        $this->logFile = $this->getTimedFilename();
        $this->logFile = $this->getTimedFilename();
        $this->close();
    }

}
