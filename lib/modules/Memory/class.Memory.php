<?php
/**
* LoadAvg - Server Monitoring & Analytics
* http://www.loadavg.com
*
* Memory Module for LoadAvg
* 
* @version SVN: $Id$
* @link https://github.com/loadavg/loadavg
* @author Karsten Becker
* @copyright 2014 Sputnik7
*
* This file is licensed under the Affero General Public License version 3 or
* later.
*/

class Memory extends LoadAvg
{
	public $logfile; // Stores the logfile name & path

	/**
	 * __construct
	 *
	 * Class constructor, appends Module settings to default settings
	 *
	 */
	public function __construct()
	{
		$this->setSettings(__CLASS__, parse_ini_file(strtolower(__CLASS__) . '.ini.php', true));
	}

	/**
	 * logMemoryUsageData
	 *
	 * Retrives data and logs it to file
	 *
	 * @param string $type type of logging default set to normal but it can be API too.
	 * @return string $string if type is API returns data as string
	 *
	 */

	public function logData( $type = false )
	{
		$class = __CLASS__;
		$settings = LoadAvg::$_settings->$class;

		$timestamp = time();

		/* 
			grab this data directly from /proc/meminfo in a single call
			egrep --color 'Mem|Cache|Swap' /proc/meminfo
		*/
		
		exec( "egrep 'MemTotal|MemFree|SwapTotal|SwapFree' /proc/meminfo | awk -F' ' '{print $2}'", $sysmemory );

		$totalmemory = $sysmemory[0];
		$freememory = $sysmemory[1];
		$memory = $totalmemory - $freememory;

		$totalswap = $sysmemory[2];
		$freeswap = $sysmemory[3];
		$swap = $totalswap - $freeswap;
	    
	    $string = $timestamp . '|' . $memory . '|' . $swap . '|' . $totalmemory . "\n";

	    //echo 'DATA:'  . $string .  "\n" ;

		$filename = sprintf($this->logfile, date('Y-m-d'));
		$this->safefilerewrite($filename,$string,"a",true);

		if ( $type == "api")
			return $string;
		else
			return true;
	}

	/**
	 * getMemoryUsageData
	 *
	 * Gets data from logfile, formats and parses it to pass it to the chart generating function
	 *
	 * @return array $return data retrived from logfile
	 *
	 */
	
	public function getUsageData( )
	{
		$class = __CLASS__;
		$settings = LoadAvg::$_settings->$class;

		//grab the log file data needed for the charts
		$contents = array();

		//$contents = LoadAvg::parseLogFileData($this->logfile);
		$logStatus = LoadAvg::parseLogFileData($this->logfile, $contents);

			/*
			for collectd data is as follows...
	     	 0 - timestamp 
	     	 1 - buffered
	     	 2 - cached
	     	 3 - used
	     	 4 - free

			$memory =  1 + 2 + 3;
			$totalmemory = 1 + 2 + 3 + 4;
			
			*/

			/*
			for loadavg is
			 0 - timestamp
			 1 - memory
			 2 - swap
			 3 - totalmemory
			*/

		//contents is now an array!!! not a string
		// is this really faster than strlen ?
		
		if (!empty($contents) && $logStatus) {

			$return = $usage = $args = array();

			$swap = array();
			$usageCount = array();
			$dataArray = $dataArrayOver = $dataArraySwap = array();


			$chartArray = array();

			//get log data in array for charting
			$this->getChartData ($chartArray, $contents );


			$totalchartArray = (int)count($chartArray);

			//need to get memory size in order to process data properly
			//is it better before loop or in loop
			//what happens if you resize disk on the fly ? in loop would be better
			$memorySize = 0;

			//map the collectd disk size to our disk size here
			//subtract 1 from size of array as a array first value is 0 but gives count of 1
			if ( LOGGER == "collectd")
			{	
				$memorySize = ( $chartArray[$totalchartArray-1][1] + 
								$chartArray[$totalchartArray-1][2] + 
								$chartArray[$totalchartArray-1][3] ) / 1024;
			} else {

				$memorySize = $chartArray[$totalchartArray-1][3] / 1024;
			}

			//need to start logging total memory

			// get from settings here for module
			// true - show MB
			// false - show percentage

			$displayMode =	$settings['settings']['display_limiting'];

			for ( $i = 0; $i < $totalchartArray; ++$i) {				
				$data = $chartArray[$i];
				
				//check for redline
				$redline = ($this->checkRedline($data,4));

				//map the collectd data to our data here
				if ( LOGGER == "collectd")
				{

					$dmemory =  $data[1] + $data[2] + $data[3]; 

					$dtotalmemory = $data[1] + $data[2] + $data[3] + $data[4];

					$data[1] = $dmemory;
					$data[2] = 0;  //used for swap in loadavgd... not used in collectd
					$data[3] = $dtotalmemory;

				}


				if (  (!$data[1]) ||  ($data[1] == null) || ($data[1] == "")  )
					$data[1]=0.0;

				//used to filter out redline data from usage data as it skews it
				if (!$redline) {
					$usage[] = ( $data[1] / 1024 );
					$percentage_used =  ( $data[1] / $data[3] ) * 100; // DIV 0 REDLINE
				} else {
					$percentage_used = 0;
				}
			
				$timedata = (int)$data[0];
				$time[( $data[1] / 1024 )] = date("H:ia", $timedata);

				$usageCount[] = ($data[0]*1000);

				if ( LoadAvg::$_settings->general['chart_type'] == "24" ) 
					$timestamps[] = $data[0];

				if ($displayMode == 'true' ) {
					// display data using MB
					$dataArray[$data[0]] = "[". ($data[0]*1000) .", ". ( $data[1] / 1024 ) ."]";

					if ( $percentage_used > $settings['settings']['overload'] )
						$dataArrayOver[$data[0]] = "[". ($data[0]*1000) .", ". ( $data[1] / 1024 ) ."]";

					//swapping
					if ( isset($data[2])  ) {
						$dataArraySwap[$data[0]] = "[". ($data[0]*1000) .", ". ( $data[2] / 1024 ) ."]";
						$swap[] = ( $data[2] / 1024 );

					}

				} else {
					// display data using percentage
					$dataArray[$data[0]] = "[". ($data[0]*1000) .", ". $percentage_used ."]";

					if ( $percentage_used > $settings['settings']['overload'])
						$dataArrayOver[$data[0]] = "[". ($data[0]*1000) .", ". $percentage_used ."]";

					//swapping
					if ( isset($data[2])  ) {

						if (!$redline) 
							$swap_percentage = ( ($data[2] / $data[3])  * 100); // DIV 0 REDLINE
						else
							$swap_percentage = 0;
						
						$dataArraySwap[$data[0]] = "[". ($data[0]*1000) .", ". $swap_percentage ."]";
						$swap[] = $swap_percentage;
					}

				}

			}

			//echo $percentage_used; die;

			end($swap);
			$swapKey = key($swap);
			$swap = $swap[$swapKey];

			//check for displaymode as we show data in MB or %
			if ($displayMode == 'true' )

			{
				$mem_high = max($usage);
				$mem_low  = min($usage); 
				$mem_mean = array_sum($usage) / count($usage);

				if  ( $swap > 1 ) {
					$ymax = $mem_high*1.05;
					$ymin = $swap/2;
				}
				else {
					$ymax = $mem_high;
					$ymin = $mem_low;
				}

			} else {

				$mem_high=   ( max($usage) / $memorySize ) * 100 ;				
				$mem_low =   ( min($usage) / $memorySize ) * 100 ;
				$mem_mean =  ( (array_sum($usage) / count($usage)) / $memorySize ) * 100 ;

				//these are the min and max values used when drawing the charts
				//can be used to zoom into datasets
				$ymin = 1;
				$ymax = 100;

			}

			$mem_high_time = $time[max($usage)];
			$mem_low_time = $time[min($usage)];
			$mem_latest = ( ( $usage[count($usage)-1]  )  )    ;		

			//TODO need to get total memory here
			//as memory can change dynamically in todays world!

			$mem_total = $memorySize;
			$mem_free = $mem_total - $mem_latest;

		
			// values used to draw the legend
			$variables = array(
				'mem_high' => number_format($mem_high,2),
				'mem_high_time' => $mem_high_time,
				'mem_low' => number_format($mem_low,2),
				'mem_low_time' => $mem_low_time,
				'mem_mean' => number_format($mem_mean,2),
				'mem_latest' => number_format($mem_latest,2),
				'mem_total' => number_format($mem_total,2),
				'mem_swap' => number_format($swap,2),
			);
		
			// get legend layout from ini file
			$return = $this->parseInfo($settings['info']['line'], $variables, __CLASS__);

			if (count($dataArrayOver) == 0) { $dataArrayOver = null; }

			ksort($dataArray);
			if (!is_null($dataArrayOver)) ksort($dataArrayOver);
			if (!is_null($dataArraySwap)) ksort($dataArraySwap);


			// dataString is cleaned data used to draw the chart
			// dataSwapString is the swap usage
			// dataOverString is if we are in overload

			$dataString = "[" . implode(",", $dataArray) . "]";
			$dataOverString = is_null($dataArrayOver) ? null : "[" . implode(",", $dataArrayOver) . "]";
			$dataSwapString = is_null($dataArraySwap) ? null : "[" . implode(",", $dataArraySwap) . "]";

			$return['chart'] = array(
				'chart_format' => 'line',
				'chart_avg' => 'avg',

				'ymin' => $ymin,
				'ymax' => $ymax,
				'xmin' => date("Y/m/d 00:00:01"),
				'xmax' => date("Y/m/d 23:59:59"),
				'mean' => $mem_mean,
				'dataset_1' => $dataString,  
				'dataset_1_label' => 'Memory Usage',

				'dataset_2' => $dataOverString,
				'dataset_2_label' => 'Overload',
				
				'dataset_4' => $dataSwapString,				// how is it used
				'dataset_4_label' => 'Swap',
				
				'overload' => $settings['settings']['overload']
			);

			return $return;	
		} else {

			return false;	
		}
	}

	/**
	 * genChart
	 *
	 * Function witch passes the data formatted for the chart view
	 *
	 * @param array @moduleSettings settings of the module
	 * @param string @logdir path to logfiles folder
	 *
	 */


	public function genChart($moduleSettings)
	{

		//get chart settings for module
		$charts = $moduleSettings['chart']; //contains args[] array from modules .ini file

		$module = __CLASS__;

		//this loop is for modules that have multiple charts in them - like mysql and network
		$i = 0;
		foreach ( $charts['args'] as $chart ) {
			$chart = json_decode($chart);

			//get data range we are looking at - need to do some validation in this routine
			$dateRange = $this->getDateRange();

			//get the log file NAME or names when there is a range
			//returns multiple files when multiple log files
			$this->logfile = $this->getLogFile($chart->logfile,  $dateRange, $module );

			// find out main function from module args that generates chart data
			// in this module its getData above
			$caller = $chart->function;


			//check if function takes settings via GET url_args 
			$functionSettings =( (isset($moduleSettings['module']['url_args']) && isset($_GET[$moduleSettings['module']['url_args']])) 
				? $_GET[$moduleSettings['module']['url_args']] : '2' );

			//need to update for when more than 1 logfile ?
			//cant do file exists here
			if (!empty($this->logfile)) {

				$i++;				
				$logfileStatus = true;

				//call modules main function and pass over functionSettings
				if ($functionSettings) {
					$chartData = $this->$caller( $functionSettings );
				} else {
					$chartData = $this->$caller( );
				}

			} else {
				//no log file so draw empty charts
				$i++;				
				$logfileStatus = false;
			}

			//now draw chart to screen
			include APP_PATH . '/views/chart.php';
		}
	}


}
