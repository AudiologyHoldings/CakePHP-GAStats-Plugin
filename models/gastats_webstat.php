<?php

class GastatsWebstat extends GastatsAppModel {
	public $name = "GastatsWebstat";
	public $useTable = "gastats_webstats";
	public $stats_type = 'webstats';
		
	/**
	* Pull webads stats and aggregate the URLs and store in gastats_ads
	* If refresh is set to true it will repull the data from Google.
	*
	*/
	public function processGAStats($start_date=null, $end_date=null, $refresh=false) {
		$GastatsRaw = ClassRegistry::init('Gastats.GastatsRaw');
				
		if ($refresh) {
			$GastatsRaw->getGAData($this->stats_type,$start_date,$end_date,true);
			$this->purgeWebStats($start_date, $end_date);
		}
		
		$stats = $GastatsRaw->getStats($this->stats_type,$start_date,$end_date);
		
		foreach ($stats as $stat) {
			$stat = $stat['GastatsRaw'];
			$data = array(
				'start_date'	=> $start_date,
				'end_date' 		=> $end_date,
				'metric' 		=> $stat['key'],
				'value' 		=> $stat['value'],
				);
			$this->create();
			$this->save($data);
		}
	}
	
	/**
	*
	*/
	function purgeWebStats($start_date=null, $end_date=null) {
		$conditions = compact('start_date', 'end_date');
		$this->deleteAll($conditions);
	}
	
	
}
?>
