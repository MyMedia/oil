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
	public static function run($task, $args = array())
	{
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

		//Check if logs exists
		if (\DBUtil::table_exists('task_log'))
		{
			$hostname = $_SERVER['FUEL_HOSTNAME'] ?: '';

			$params   = json_encode($args);

			//Check if task don't running
			if ($prev = \DB::select()->from('task_log')->where('task', $task)->where('params', $params)->where('hostname', $hostname)->order_by('id', 'desc')->limit(1)->execute()->as_array())
			{
				$prev = $prev[0];
				//If task is longer than 15 minutes
				if ($prev['status'] == 'running' and $prev['created_at'] < (time() - 60 * 15))
				{
					$prev['status']    = 'error';
					$prev['error_message']     = 'Timeout: Task was running over 15 minutes';
					$prev['finish_at'] = time();
					\DB::update('task_log')->set($prev)->where('id', $prev['id'])->execute();
				}

				// If task is active, stop this cron
				if ($prev['status'] == 'running')
				{
					\Cli::write('The same task still running.');
					return;
				}
			}	

			//Create new Cron log
			$log             = array();
			$log['status']   = 'running';
			$log['created_at']   = time();
			$log['hostname'] = $hostname;
			$log['task']     = $task;
			$log['params']   = $params;

			// get inserted log ID
			$log_id = \DB::insert('task_log')->set($log)->execute()[0];
		}

		if (is_callable(array($new_task, $method)))
		{
			try
			{
				if ($return = call_fuel_func_array(array($new_task, $method), $args))
				{
					\Cli::write($return);
				}
				isset($log) and $log['status'] = 'ok';
			}
			catch (Exception $e)
			{
				isset($log) and $log['status'] = 'error';
				isset($log) and $log['error_message'] = $e->getMessage();
			}

			isset($log) and $log['finish_at'] = time();
			isset($log) and \DB::update('task_log')->set($log)->where('id', $log_id)->execute();
		}
		else
		{
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
