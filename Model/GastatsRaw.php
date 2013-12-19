<?php

class GastatsRaw extends GastatsAppModel {
	public $name = "GastatsRaw";
	public $useTable = "gastats_raws";
	protected $stats_data = array();
	public $max_results = 5000;
	protected $results_cap = 10000;
	public $errors = array();
	public $page_path = '';
	public $stat_type = 'generic-notracking-noadmin';
	protected $metric_count = 0;
	
	/*Prdefined stat types.  Don't add ga: in front of the metrics/dimensions/filters (they will be added later)*/
	public $stat_types = array(
		'generic' => array(
			'metrics' => array('pageviews'),
			'dimensions' => array('pagePath'),
			),
		'generic-notracking' => array(
			'metrics' => array('pageviews'),
			'dimensions' => array('pagePath'),
			'filters' => array('pagePath!@track_'), 
			),
		'generic-notracking-noadmin' => array(
			'metrics' => array('pageviews'),
			'dimensions' => array('pagePath'),
			'filters' => array('pagePath!@track_', 'pagePath!~^/admin.*', 'pagePath!~^/api.*'), 
			),
		//-------
		'webads' => array(
			'metrics' => array('pageviews'),
			'dimensions' => array('pagePath'),
			'filters' => array('pagePath=~^/track_.*'),
			),
		'webad-events' => array(
			'metrics' => array('totalEvents'),
			'dimensions' => array('eventAction'),
			'filters' => array('eventAction=~^/track_[banner|spotlight]'),
			),
		//-------
		'webstats' => array(
			'metrics' => array('pageviews','visitors','visits','timeOnSite', 'avgTimeOnSite'),
			'dimensions' => array('year'),
			),
		//-------
		'webchannels' => array(
			'metrics' => array('pageviews','uniquePageviews','timeOnpage', 'avgTimeOnPage','exits'),
			'dimensions' => array('pagePath'),
			'filters' => array(), //dynamically set at run time via $this->page_path, requires 'channels' data in database.php
			),
		//-------
		'country' => array(
			'metrics' => array('visits'),
			'dimensions' => array('country'),
			)
		);
	
	/**
	*
	*/
	public function config($config = array()) {
		$this->loadGA();
		if (!empty($config) && is_array($config)) {
			$this->GoogleAnalytics->config = Set::merge($this->GoogleAnalytics->config, $config);
		}
		return $this->GoogleAnalytics->config;
	}
	
	/**
	*
	*
	*/
	public function processGAStats($start_date=null,$end_date=null) {
		//run defaults
		$this->page_path='';
		$this->purgeStats($this->stat_type, $start_date, $end_date);
		return $this->getGAData($this->stat_type, $start_date, $end_date, true);
	}
	
	/**
	* Query gastats_raws table
	*
	*/
	public function getStats($stat_type=null,$start_date=null,$end_date=null,$key=null) {
		$conditions = array('stat_type' => $stat_type,'start_date >=' => $start_date,'end_date <=' => $end_date);
		if (!empty($key)) {
			$conditions['GastatsRaw.key'] = $key;
		}
		$results = $this->find('all',compact('conditions'));
		return $results;
	}
	
	/**
	*
	*/
	public function purgeStats($stat_type = null, $start_date=null, $end_date=null, $authorize=false) {
		if ($stat_type == 'all' && $start_date == 'all' && $end_date = 'all' && $authorize) {
			//this will remove all data from the table
			$conditions = array('1');
		} elseif ($stat_type == 'all' && $start_date == 'all' && $end_date = 'all') {
			$this->errors[] = 'Missing authorization.  Stats not purged.';
			return false;
		} else {
			$conditions = array('stat_type'=>$stat_type, 'start_date' => $start_date, 'end_date' => $end_date);	
		}
		return $this->deleteAll($conditions);
	}
	
	/**
	*
	*/
	public function getGAData($stat_type=null, $start_date=null, $end_date=null, $paginate=false, $options = array()) {
		$this->loadGA();
		$this->stats_data = null;
		$this->stat_types = (isset($this->GoogleAnalytics->config['stat_types']) ? Set::merge($this->stat_types, $this->GoogleAnalytics->config['stat_types']) : $this->stat_types);
		if(!empty($start_date) && !empty($end_date)) {
			if (!empty($stat_type)) {
				$options = array_merge($this->stat_types[$stat_type], $options); //add/replace stat_type parameters				
			}
			$options['start-date'] = $start_date;
			$options['end-date'] = $end_date;
			$options['max-results'] = (isset($options['max-results']) ? $options['max-results'] : $this->max_results);
			if ($options['max-results'] >= $this->results_cap) {
				$this->errors[] = "Maximum allowed results of $this->results_cap exceeded: ".$options['max-results'];
				return false;
			}
			if (!empty($this->page_path)) {
				$options['filters'] = Set::merge($options['filters'],array('pagePath=~^/'.$this->page_path.'/?$'));
			}
			$this->metric_count = count($options['metrics']);//will define how the data is stored
			$response = $this->GoogleAnalytics->report($options);
			
			$xml = $this->parseGAData($response);
			if (isset($xml['feed']['entry']) && is_array($xml['feed']['entry'])) {
				$num_entries = count($xml['feed']['entry']);
				if ($num_entries > 0) {
					$this->storeGAData($xml,$stat_type,$start_date,$end_date);
					$page_count = 1;
					if ($paginate && ($num_entries == $options['max-results'])) {
						$start_index = 1; //default
						echo "Pulled page $page_count with $num_entries results.";
						//Loop until no more data
						while (isset($xml['feed']['entry']) && count($xml['feed']['entry']) > 0) {
							$start_index += $options['max-results'];
							$options['start-index'] = $start_index;
							$page_count++;
							$num_entries = 0;
							$response = $this->GoogleAnalytics->report($options);
							$xml = $this->parseGAData($response);
							if (isset($xml['feed']['entry']) && is_array($xml['feed']['entry'])) {
								$num_entries =  count($xml['feed']['entry']);
								echo "Pulled page $page_count with $num_entries results.";
								$this->storeGAData($xml,$stat_type,$start_date,$end_date);
							}
						}
					}	
				}	
			}
			if (!$this->errors()) {
				//Purge old stats matching stat type and date range
				if ($stat_type != 'webchannels') {
					$this->purgeStats($stat_type, $start_date, $end_date);	
				}
				return $this->storeGAData('save', $stat_type, $start_date, $end_date);
			}
		}
		return false;	
	}
	
	/**
	*
	*/
	function storeGAData ($xml=null, $stat_type=null, $start_date=null, $end_date=null) {
		if ($xml == 'save' && !empty($stat_type) && !empty($start_date) && !empty($end_date)) {
			$this->stats_data = (is_array($this->stats_data) ? $this->stats_data : array());
			foreach ($this->stats_data as $stat_type => $stat_details) {
				foreach ($stat_details as $key =>$val) {
					$savedata = array('start_date'=>$start_date, 'end_date' => $end_date, 'key' => $key, 'value' =>$val, 'stat_type'=>$stat_type);
					//debug($savedata);
					$this->create(false);
					if (!$this->save($savedata)) {
						$this->errors[] = "Error saving GA data. $stat_type $start_date $end_date";
						return false;
					}		
				}
			}
			
		} else {
			//store gathered data in array while gathering more data
			$entries = (isset($xml['feed']['entry']) ? $xml['feed']['entry'] : array());
			if (strpos($stat_type,'webchannels') !== false && $this->metric_count > 1) {
				//store multiple metrics for specified page path
				//should only be one result per channel
				$attr = 0;
				foreach ($this->stat_types[$stat_type]['metrics'] as $metric) {
					if (isset($entries['dxp:metric'][$attr.'_attr']['value'])) {
						$this->stats_data[$stat_type][$this->page_path.'|'.$metric] = $entries['dxp:metric'][$attr.'_attr']['value'];
					} else {
						//Check if multiple results due to page path differences
						foreach ($entries as $entry) {
							if (isset($entry['dxp:metric'][$attr.'_attr']['value']) && strpos($entry['title'], 'ga:pagePath=') !== false) {
								$temp_page_path = str_replace("ga:pagePath=/","", $entry['title']);
								$temp_page_path = trim($temp_page_path,"/");
								if ($temp_page_path == $this->page_path) {
									$this->stats_data[$stat_type][$this->page_path.'|'.$metric] = $entry['dxp:metric'][$attr.'_attr']['value'];
								}
							}
						}
					}
					$attr++;
				}
			}
			//if (in_array($stat_type, array('country','webads','generic', 'generic-notracking', 'generic-notracking-noadmin'))) {
			elseif ($this->metric_count == 1) {
				//store single metric value for single dimension
				foreach ($entries as $data) {
					//if (in_array($data['dxp:metric_attr']['name'],array('ga:pageviews','ga:visits')) && $data['dxp:metric_attr']['value']>0) {
					if ($data['dxp:metric_attr']['value']>0) {
						 $key = $data['dxp:dimension_attr']['value'];
						 $value = $data['dxp:metric_attr']['value'];
					 if (isset($this->stats_data[$stat_type][$key])) {
						 $this->stats_data[$stat_type][$key] = $this->stats_data[$key] + $value;
					 } else {
						 $this->stats_data[$stat_type][$key] = $value;
					 }
					}	
				}
			//} elseif (strpos($stat_type,'webstats') !== false) {
			} elseif ($this->metric_count > 1) {
				//store multiple metrics for site 
				$attr = 0;
				foreach ($this->stat_types[$stat_type]['metrics'] as $metric) {
					$this->stats_data[$stat_type][$metric] = $entries['dxp:metric'][$attr.'_attr']['value'];
					$attr++;
				}
			}
		}
		
		return true;
	}
	
	//====================================
	
	function parseGAData($data) {
		$xml = null;
		if(stripos($data,'xml')!==false) {
			$xml = $this->xml2array($data); 
		} else {
			//possible error returned
			$this->errors[] = $data;
		}
		return $xml;
	}
		
	public function errors($display=false) {
		if (count($this->errors) > 0) {
			if ($display) {
				print_r($this->errors);
			}
			return true;
		}
		return false;
	}
	
	//======================================
	
	public function getContent($start_date=null, $end_date=null, $limit=null, $path='', $wildcard=false) {
		//Get 'generic' stat type
		$stat_type = $this->stat_type;
		if (!is_null($start_date) && !is_null($end_date)) {
			$conditions = compact('start_date', 'end_date','stat_type');
		} else {
			$conditions = compact('stat_type');
		}
		$wildcard = ($wildcard ? '%' : '');
		if (!empty($path)) {
			$conditions['GastatsRaw.key LIKE'] = "$path$wildcard";
		}
		$order = 'GastatsRaw.start_date ASC, GastatsRaw.key ASC';
		$content = $this->find('all',compact('conditions','order','limit'));
		return $content;
		
	}

	//====================================

	public function processGAStatsAll($start=null, $stop=null) {
		if (empty($start) || empty($stop)) {
			//default to last month
			$start = date('Y-m-01', strtotime(date('Y-m-01').' -1 month'));
			$stop = date('Y-m-t', strtotime(date('Y-m-01').' -1 month'));
			echo "Date range not supplied, using: $start to $stop\n";
		}
		$GastatsAd = ClassRegistry::init('Gastats.GastatsAd');
		$GastatsCountry = ClassRegistry::init('Gastats.GastatsCountry');
		$GastatsWebchannel = ClassRegistry::init('Gastats.GastatsWebchannel');
		$GastatsWebstat = ClassRegistry::init('Gastats.GastatsWebstat');
		
		echo "Pulling the Raw generic stats\n";
		$this->processGAStats($start, $stop);
		echo "Pulling the Ad stats\n";
		$GastatsAd->processGAStats($start,$stop,true);
		echo "Pulling the Country stats\n";
		$GastatsCountry->processGAStats($start,$stop,true);
		echo "Pulling the Webchannel stats\n";
		$GastatsWebchannel->processGAStats($start,$stop,true);
		echo "Pulling the Webstat stats\n";
		$GastatsWebstat->processGAStats($start,$stop,true); 
		return true;
	}
}
?>