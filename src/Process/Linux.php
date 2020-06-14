<?php
namespace EasyTask\Process;

use EasyTask\Command;
use EasyTask\Env;
use \Exception as Exception;
use \Throwable as Throwable;
use \Closure as Closure;
use EasyTask\Helper;

/**
 * Class Linux
 * @package EasyTask\Process
 */
class Linux extends Process
{
    /**
     * 进程启动时间
     * @var int
     */
    protected $startTime;

    /**
     * 进程命令管理
     * @var array
     */
    protected $commander;

    /**
     * 任务列表
     * @var array
     */
    protected $taskList;

    /**
     * 任务总数
     * @var int
     */
    protected $taskCount;

    /**
     * 进程执行记录
     * @var array
     */
    protected $processList = [];

    /**
     * 构造函数
     * @var array $taskList
     */
    public function __construct($taskList)
    {
        $this->taskList = $taskList;
        $this->setTaskCount();
        $this->startTime = time();
        $this->commander = new Command();
        if (Env::get('canAsync'))
        {
            Helper::openAsyncSignal();
        }
    }

    /**
     * 开始运行
     */
    public function start()
    {
        //发送命令
        $this->commander->send([
            'type' => 'start',
            'msgType' => 2
        ]);

        //异步处理
        if (Env::get('daemon'))
        {
            Helper::setMask();
            $this->fork(
                function () {
                    $sid = posix_setsid();
                    if ($sid < 0)
                    {
                        Helper::showError('set child processForManager failed,please try again');
                    }
                    $this->allocate();
                },
                function () {
                    pcntl_wait($status, WNOHANG);
                    $this->status();
                }
            );
        }

        //同步处理
        $this->allocate();
    }

    /**
     * 分配进程处理任务
     */
    protected function allocate()
    {
        if ($this->canWriteStd())
        {
            Helper::setStdClose();
        }
        foreach ($this->taskList as $item)
        {
            //提取参数
            $prefix = Env::get('prefix');
            $item['data'] = date('Y-m-d H:i:s');
            $item['alas'] = "{$prefix}_{$item['alas']}";
            $used = $item['used'];

            //根据Worker数分配进程
            for ($i = 0; $i < $used; $i++)
            {
                $this->forkItemExec($item);
            }
        }

        //常驻守护
        $this->daemonWait();
    }

    /**
     * 创建子进程
     * @param Closure $childInvoke
     * @param Closure $mainInvoke
     */
    protected function fork($childInvoke, $mainInvoke)
    {
        $pid = pcntl_fork();
        if ($pid == -1)
        {
            Helper::showError('fork child process failed,please try again');
        }
        elseif ($pid)
        {
            $mainInvoke($pid);
        }
        else
        {
            $childInvoke();
        }
    }

    /**
     * 创建任务执行的子进程
     * @param array $item
     */
    protected function forkItemExec($item)
    {
        $this->fork(
            function () use ($item) {
                $this->invoker($item);
            },
            function ($pid) use ($item) {
                //write_log
                $ppid = posix_getpid();
                $this->processList[] = ['pid' => $pid, 'name' => $item['alas'], 'item' => $item, 'started' => $item['data'], 'time' => $item['time'], 'status' => 'active', 'ppid' => $ppid];
                //set not block
                pcntl_wait($status, WNOHANG);
            }
        );
    }

    /**
     * 执行器
     * @param array $item
     * @throws Throwable
     */
    protected function invoker($item)
    {
        //输出信息
        $item['pid'] = getmypid();
        $item['ppid'] = posix_getppid();
        $text = "this worker {$item['alas']}(pid:{$item['pid']})";
        Helper::writeTypeLog("$text is start");

        //进程标题
        Helper::cli_set_process_title($item['alas']);

        //Kill信号
        pcntl_signal(SIGTERM, function () use ($text) {
            Helper::writeTypeLog("listened kill command, $text not to exit the program for safety");
        });

        //执行任务
        if (is_int($item['time']) || is_float($item['time']))
        {
            if ($item['time'] === 0) $this->invokerByDirect($item);
            Env::get('canEvent') ? $this->invokeByEvent($item) : $this->invokeByDefault($item);
        }
        elseif (is_string($item['time']))
        {
            $this->invokeByCron($item);
        }
        else
        {
            Helper::showError("abnormal task time:{$item['time']}");
        }
    }

    /**
     * 通过闹钟信号执行
     * @param array $item
     */
    protected function invokeByDefault($item)
    {
        //安装信号管理
        pcntl_signal(SIGALRM, function () use ($item) {
            pcntl_alarm($item['time']);
            $this->execute($item);
        }, false);

        //发送闹钟信号
        pcntl_alarm($item['time']);

        //挂起进程(同步调用信号,异步CPU休息)
        while (true)
        {
            //CPU休息
            Helper::sleep(1);

            //信号处理(同步/异步)
            if (!Env::get('canAsync')) pcntl_signal_dispatch();
        }
    }

    /**
     * 检查常驻进程是否存活
     * @param array $item
     */
    protected function checkDaemonForExit($item)
    {
        if (!posix_kill($item['ppid'], 0))
        {
            Helper::writeTypeLog("listened exit command, this worker {$item['alas']}(pid:{$item['pid']}) is safely exited", 'info', true);
        }
    }

    /**
     * 守护进程常驻
     */
    protected function daemonWait()
    {
        //设置进程标题
        Helper::cli_set_process_title(Env::get('prefix'));

        //输出信息
        $pid = getmypid();
        $text = "this manager(pid:{$pid})";
        Helper::writeTypeLog("$text is start");
        if (!Env::get('daemon'))
        {
            Helper::showTable($this->processStatus(), false);
            Helper::showInfo('start success,press ctrl+c to stop');
        }

        //Kill信号
        pcntl_signal(SIGTERM, function () use ($text) {
            Helper::writeTypeLog("listened kill command $text is safely exited", 'info', true);
        });

        //挂起进程
        while (true)
        {
            //CPU休息
            Helper::sleep(1);

            //接收命令start/status/stop
            $this->commander->waitCommandForExecute(2, function ($command) use ($text) {
                $exitText = "listened exit command, $text is safely exited";
                $statusText = "listened status command, $text is reported";
                $forceExitText = "listened exit command, $text is safely exited";
                if ($command['type'] == 'start')
                {
                    if ($command['time'] > $this->startTime)
                    {
                        Helper::writeTypeLog($forceExitText);
                        posix_kill(0, SIGKILL);
                    }
                }
                if ($command['type'] == 'status')
                {
                    $report = $this->processStatus();
                    $this->commander->send([
                        'type' => 'status',
                        'msgType' => 1,
                        'status' => $report,
                    ]);
                    Helper::writeTypeLog($statusText);
                }
                if ($command['type'] == 'stop')
                {
                    if ($command['force'])
                    {
                        Helper::writeTypeLog($forceExitText);
                        posix_kill(0, SIGKILL);
                    }
                    else
                    {
                        Helper::writeTypeLog($exitText);
                        exit();
                    }
                }

            }, $this->startTime);

            //信号调度
            if (!Env::get('canAsync')) pcntl_signal_dispatch();

            //检查进程
            if (Env::get('canAutoRec')) $this->processStatus();
        }
    }

    /**
     * 查看进程状态
     * @return array
     */
    protected function processStatus()
    {
        $report = [];
        foreach ($this->processList as $key => $item)
        {
            //提取参数
            $pid = $item['pid'];

            //进程状态
            $rel = pcntl_waitpid($pid, $status, WNOHANG);
            if ($rel == -1 || $rel > 0)
            {
                //标记状态
                $item['status'] = 'stop';

                //进程退出,重新fork
                if (Env::get('canAutoRec'))
                {
                    $this->forkItemExec($item['item']);
                    Helper::writeTypeLog("the worker {$item['name']}(pid:{$pid}) is stop,try to fork new one");
                    unset($this->processList[$key]);
                }
            }

            //记录状态
            unset($item['item']);
            $report[] = $item;
        }

        return $report;
    }
}