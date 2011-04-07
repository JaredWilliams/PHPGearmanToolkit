<?php

namespace ProcessControl
{
	class Utility
	{
		protected function __construct() { }
	
		static function fork($work)
		{
			$pid = pcntl_fork();
			switch ($pid)
			{
				case 0:
					$work();		// exit($work()); ?
					exit(0);

				case -1:
					throw new \RuntimeException('Failed to fork');
					break;

				default:
					return $pid;
			}
		}
	
		static function setAlarm($f = SIG_IGN, $seconds = 0)
		{
			pcntl_signal(SIGALRM, $f);
			return pcntl_alarm($seconds);
		}
	}

	class Daemon
	{
		protected $user;
		
		function runAs($userName)
		{
			if (posix_getuid() != 0 && posix_geteuid() != 0)
				return;

			$this->user = posix_getpwnam($userName);
			if (!$this->user)
				throw new \InvalidArgumentException("Cannot find user '$userName'.");
		}
		
		function run($work)
		{
			if (1 === posix_getppid())
				return;

			if ($this->user)
				if (!posix_setgid($this->user['gid']) || !posix_setuid($this->user['uid']))
					throw new \RuntimeException("Unable to switch to user '{$this->user['name']}'");

			Utility::fork(
				function() use ($work)
				{
					if (-1 === posix_setsid())
						throw new \RuntimeException('Unable to set setsid()');

					if (false === chdir('/'))
						throw new \RuntimeException('Unable to chdir(\'/\')');

					umask(0);

					Utility::fork($work);
				}
			);
		}
	}
}

namespace Fork
{
	use ProcessControl\Utility;

	class Children
	{
		protected $running;

		function __construct()
		{
			$this->running = array();
		}

		function areRunning() { return !empty($this->running); }

		function createProcesses($work, $workerCount)
		{
			for ($i = 0; $i < $workerCount; ++$i)
			{
				$pid = Utility::fork($work);
				$this->running[$pid] = $work;
			}
		}

		function removeExitedProcess($pid)
		{
			if (!isset($this->running[$pid]))
				return false;

			$work = $this->running[$pid];
			unset($this->running[$pid]);
			return $work;
		}

		function restartExitedProcess($pid)
		{
			$work = $this->removeExitedProcess($pid);
			if ($work)
				$this->createProcesses($work, 1);
		}

		function broadcastSignal($signal)
		{
			foreach($this->running as $pid => $work)
				posix_kill($pid, $signal);
		}
		
		function killAll()
		{
			$this->broadcastSignal(SIGKILL);
			$this->running = array();
		}
	}

	class Manager
	{
		protected $childrenFactory;
		protected $maxSecondsForShutdown = 30;

		private $children;

		function __construct($childrenFactory)
		{
			$this->childrenFactory = $childrenFactory;
		}

		protected function setSignalHandler($handler)
		{
			pcntl_signal(SIGTERM, $handler, false);
			pcntl_signal(SIGINT, $handler, false);
			pcntl_signal(SIGHUP, $handler, false);
		}

		protected function getChildren()
		{
			if (!$this->children)
				$this->children = $this->childrenFactory->__invoke();
			return $this->children;
		}

		function __invoke()
		{
			$children = $this->getChildren();

			$this->setSignalHandler(array($this, 'signalHandler'));
			$status = null;
			$pid = pcntl_wait($status);
			while (0 != $pid && $children->areRunning())
			{
				if (0 < $pid)
					$children->restartExitedProcess($pid);
				pcntl_signal_dispatch();
				$pid = pcntl_wait($status);
			}
			$this->setSignalHandler(SIG_DFL);
		}

		protected function signalHandler($signal)
		{
			$children = $this->getChildren();
			switch ($signal)
			{
				case SIGHUP:
					$children->broadcastSignal(SIGTERM);
					break;

				case SIGINT:
				case SIGTERM:
					$this->setSignalHandler(SIG_DFL);

					$children->broadcastSignal($signal);
					/*
					 * Set up an alarm, to exit wait() if taking too long. 
					 * Will kill any remaining children, if the alarm triggers.
					 */
					Utility::setAlarm(function($signal) { }, $this->maxSecondsForShutdown);
					$status = null;
					declare(ticks = 1)
					{
						$pid = pcntl_wait($status);
						while ($pid > 0)
						{
							$children->removeExitedProcess($pid);
							$pid = pcntl_wait($status);
						}
					}
					Utility::setAlarm(SIG_IGN, 0);
					
					/*
					 * Should be all terminated anyway, unless the alarm triggered
					 */
					$children->killAll();
					break;

				default:
					break;
			}
		}
	}

	interface Worker
	{
		function __invoke();
	}
}

namespace Fork\Worker
{
	use ProcessControl\Utility, Fork;

	class Decorator implements Fork\Worker
	{
		protected $innerWorker;

		function __construct(Fork\Worker $innerWorker)
		{
			$this->innerWorker = $innerWorker;
		}

		function __invoke()
		{
			$this->innerWorker->__invoke();
		}
	}

	class ExitAfterDuration extends Decorator implements Fork\Worker
	{
		protected $maxSecondsToRun;

		function __construct(Fork\Worker $innerWorker, $maxSecondsToRun)
		{
			parent::__construct($innerWorker);
			$this->maxSecondsToRun = $maxSecondsToRun;
		}

		function __invoke()
		{
			Utility::setAlarm(function() { exit(0); }, $this->maxSecondsToRun);
			parent::__invoke();
			Utility::setAlarm(SIG_DFL, 0);
		}
	}

	class PeclGearman implements Fork\Worker
	{
		protected $gearmanWorkerFactory;
		
		function __construct($gearmanWorkerFactory)
		{
			$this->gearmanWorkerFactory = $gearmanWorkerFactory;
		}

		protected function createGearmanWorker()
		{
			return $this->gearmanWorkerFactory->__invoke();
		}

		protected function destroyGearmanWorker(\GearmanWorker $worker)
		{
			$worker->unregisterAll();
		}
		
		protected function wait(\GearmanWorker $worker)
		{
			// @!?
			if (@$worker->wait())
				return true;

			switch ($worker->returnCode())
			{
				case GEARMAN_SUCCESS:
					return true;

				case GEARMAN_NO_ACTIVE_FDS:
					sleep(5);
					return true;

				default:
					break;
			}
			return false;
		}
	
		protected function work(\GearmanWorker $worker)
		{		
			// @!?
			if (@$worker->work())
				return true;
	
			switch ($worker->returnCode())
			{
				case GEARMAN_SUCCESS:
				case GEARMAN_TIMEOUT:
					return true;

				case GEARMAN_IO_WAIT:
				case GEARMAN_NO_JOBS:
					return $this->wait($worker);

				default:
					break;
			}
			return false;
		}

		function __invoke()
		{
			$worker = $this->createGearmanWorker();						
			pcntl_signal_dispatch();		
			while ($this->work($worker))
			{
				pcntl_signal_dispatch();
				gc_collect_cycles();
			}
			$this->destroyGearmanWorker($worker);
		}
	}
}
