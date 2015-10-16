<?php
/**
 * Fuel
 *
 * Fuel is a fast, lightweight, community driven PHP5 framework.
 *
 * @package    Fuel
 * @version    1.7
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2014 Fuel Development Team
 * @link       http://fuelphp.com
 */

namespace Oil;

/**
 * Oil\Refine Class
 *
 * @package		Fuel
 * @subpackage	Oil
 * @category	Core
 */
class Refine
{
	protected static $_task_log_enabled;
	protected static $_hostname;
	protected static $_params;
	protected static $_task_log_id;
	
	public static function run($task, $args = array())
	{
		// task log init
		static::$_task_log_enabled = static::is_task_log_enabled();
		if(static::$_task_log_enabled)
		{
			static::$_hostname = isset($_SERVER['FUEL_HOSTNAME']) ? $_SERVER['FUEL_HOSTNAME'] : '';
			static::$_params   = json_encode(array_slice($_SERVER['argv'], 3));
		}
		
		$task = strtolower($task);

		// Make sure something is set
		if (empty($task) or $task === 'help')
		{
			static::help();
			return;
		}

		$module = false;
		list($module, $task) = array_pad(explode('::', $task), 2, null);

		if ($task === null)
		{
			$task = $module;
			$module = false;
		}

		if ($module)
		{
			try
			{
				\Module::load($module);
				$path = \Module::exists($module);
				\Finder::instance()->add_path($path, -1);
			}
			catch (\FuelException $e)
			{
				throw new Exception(sprintf('Module "%s" does not exist.', $module));
			}
		}

		// Just call and run() or did they have a specific method in mind?
		list($task, $method) = array_pad(explode(':', $task), 2, 'run');

		// Find the task
		if ( ! $file = \Finder::search('tasks', $task))
		{
			$files = \Finder::instance()->list_files('tasks');
			$possibilities = array();
			foreach($files as $file)
			{
				$possible_task = pathinfo($file, \PATHINFO_FILENAME);
				$difference = levenshtein($possible_task, $task);
				$possibilities[$difference] = $possible_task;
			}

			ksort($possibilities);

			if ($possibilities and current($possibilities) <= 5)
			{
				throw new Exception(sprintf('Task "%s" does not exist. Did you mean "%s"?', $task, current($possibilities)));
			}
			else
			{
				throw new Exception(sprintf('Task "%s" does not exist.', $task));
			}

			return;
		}

		require_once $file;

		$task = '\\Fuel\\Tasks\\'.ucfirst($task);

		$new_task = new $task;

		// The help option has been called, so call help instead
		if ((\Cli::option('help') or $method == 'help') and is_callable(array($new_task, 'help')))
		{
			$method = 'help';
		}
		else
		{
			// if the task has an init method, call it now
			is_callable($task.'::_init') and $task::_init();
		}

		// Lock task
		if(static::$_task_log_enabled and ! static::task_lock($task))
		{
			\Cli::write('Task_log: Task is already running...');
			return;
		}

		if (is_callable(array($new_task, $method)))
		{
			try
			{
				if ($return = call_fuel_func_array(array($new_task, $method), $args))
				{
					\Cli::write($return);
				}
				
				// unlock task
				static::$_task_log_enabled and static::task_unlock();
			}
			catch (\Exception $e)
			{
				// unlock task with error
				static::$_task_log_enabled and static::task_unlock($e->getMessage());
			}
			
			return;
		}
		
		\Cli::write(sprintf('Task "%s" does not have a command called "%s".', $task, $method));

		\Cli::write("\nDid you mean:\n");
		$reflect = new \ReflectionClass($new_task);

		// Ensure we only pull out the public methods
		$methods = $reflect->getMethods(\ReflectionMethod::IS_PUBLIC);
		if (count($methods) > 0)
		{
			foreach ($methods as $method)
			{
				if (strpos($method->name, '_') !== 0)
				{
					\Cli::write(sprintf("php oil [r|refine] %s:%s", $reflect->getShortName(), $method->name));
				}
			}
		}
	}

	protected static function is_task_log_enabled()
	{
		return (isset($_SERVER['FUEL_ENABLE_TASK_LOG']) and $_SERVER['FUEL_ENABLE_TASK_LOG'] == 1 and \DBUtil::table_exists('task_log'));
	}
	
	protected static function is_task_locked($task)
	{
		// select task from db
		$task_log = \DB::select()->from('task_log')->where('task', $task)->where('params', static::$_params)->where('hostname', static::$_hostname)->order_by('id', 'desc')->limit(1)->execute()->as_array();
		
		// task doesn't exists, so lock is inactive
		if( ! $task_log) return false;

		// task exists
		$task_log = $task_log[0];
		
		// handle locked task for more that 15min, so unlock
		if ($task_log['status'] == 'running' and $task_log['created_at'] < (time() - 60 * 15))
		{
			$task_log['status']        = 'error';
			$task_log['error_message'] = 'Timeout: Task was running over 15 minutes';
			$task_log['finish_at']     = time();
			
			\DB::update('task_log')->set($task_log)->where('id', $task_log['id'])->execute();
			
			return false;
		}

		// Task is not running so lock is inactive
		if ($task_log['status'] != 'running') return false;
		
		return true;
	}
	
	protected static function task_lock($task)
	{
		// task is already locked
		if(static::is_task_locked($task))
		{
			return false;
		}
		
		// task is unlocked, so create lock record
		$log = array(
			'status'     => 'running',
			'created_at' => time(),
			'hostname'   => static::$_hostname,
			'task'       => $task,
			'params'     => static::$_params,
		);

		$result = \DB::insert('task_log')->set($log)->execute();
		
		if($result)
		{
			static::$_task_log_id = $result[0];
			return true;
		}
		
		return false;
	}
	
	protected static function task_unlock($error = null)
	{
		// on error
		if($error)
		{
			$log = array(
				'status'        => 'error',
				'error_message' => $error,
				'finish_at'     => time(),
			);
			
			\DB::update('task_log')->set($log)->where('id', static::$_task_log_id)->execute();
			
			return;
		}
		
		$log = array(
			'status'        => 'ok',
			'finish_at'     => time(),
		);
		
		\DB::update('task_log')->set($log)->where('id', static::$_task_log_id)->execute();
	}

	public static function help()
	{
	    // Build a list of possible tasks for the help output
		$tasks = self::_discover_tasks();
		if (count($tasks) > 0)
		{
			$output_available_tasks = "";

			foreach ($tasks as $task => $options)
			{
				foreach ($options as $option)
				{
				    $option = ($option == "run") ? "" : ":$option";
					$output_available_tasks .= "    php oil refine $task$option\n";
				}
			}
		}

		else
		{
			$output_available_tasks = "    (none found)";
		}

		$output = <<<HELP

Usage:
    php oil [r|refine] <taskname>

Description:
    Tasks are classes that can be run through the the command line or set up as a cron job.

Available tasks:
$output_available_tasks
Documentation:
    http://docs.fuelphp.com/packages/oil/refine.html
HELP;
		\Cli::write($output);

	}

	/**
	 * Find all of the task classes in the system and use reflection to discover the
	 * commands we can call.
	 *
	 * @return array $taskname => array($taskmethods)
	 **/
	protected static function _discover_tasks()
	{
		$result = array();
		$files = \Finder::instance()->list_files('tasks');

		if (count($files) > 0)
		{
			foreach ($files as $file)
			{
				$task_name = str_replace('.php', '', basename($file));
				$class_name = '\\Fuel\\Tasks\\'.$task_name;

				require $file;

				$reflect = new \ReflectionClass($class_name);

				// Ensure we only pull out the public methods
				$methods = $reflect->getMethods(\ReflectionMethod::IS_PUBLIC);

				$result[$task_name] = array();

				if (count($methods) > 0)
				{
					foreach ($methods as $method)
					{
						strpos($method->name, '_') !== 0 and $result[$task_name][] = $method->name;
					}
				}
			}
		}

		return $result;
	}
}
