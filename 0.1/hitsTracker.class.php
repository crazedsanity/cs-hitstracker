<?php

require_once(dirname(__FILE__) .'/cs-content/cs_globalFunctions.php');
require_once(dirname(__FILE__) .'/cs-content/cs_fileSystemClass.php');
require_once(dirname(__FILE__) .'/cs-content/cs_phpDB.php');
require_once(dirname(__FILE__) .'/cs-arrayToPath/arrayToPathClass.php');
require_once(dirname(__FILE__) .'/cs-phpxml/xmlCreatorClass.php');
require_once(dirname(__FILE__) .'/cs-phpxml/xmlBuilderClass.php');
require_once(dirname(__FILE__) .'/cs-phpxml/xmlParserClass.php');




class hitsTracker {

	private $gf;
	private $fs;
	private $currentFile;
	private $db;

	//================================================================
	/**
	 * CONSTRUCTOR.
	 */
	public function __construct() {
		$this->gf = new cs_globalFunctions;
		$this->fs = new cs_fileSystemClass(dirname(__FILE__) .'/../rw/hitsTracker');
	}//end __construct()
	//================================================================



	//================================================================
	/**
	 * Log the hit (used for the public script).
	 */
	public function log_hit() {
		$data = array(
			'referer'			=> $_SERVER['HTTP_REFERER'],
			'user_agent'		=> $_SERVER['HTTP_USER_AGENT'],
			'ip'				=> $_SERVER['REMOTE_ADDR'],
			'requested_url'	=> $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']
		);
		
		$xml = new xmlCreator('hitstracker');
		foreach($data as $key=>$value) {
			$xml->add_tag($key, $value);
		}
		
		$encodedData = $xml->create_xml_string();
		
		$fileName = microtime(TRUE) . '.data';
		$this->fs->create_file($fileName);
		$this->fs->write($encodedData);
	}//end log_hit()
	//================================================================
	
	
	
	//=========================================================================
	/**
	 * Process all files created by the public script.
	 */
	public function process_all_files() {
		$fileList = $this->fs->ls();
		
		$this->gf->debugPrintOpt = 1;
		$this->gf->debugRemoveHr = 1;
		
		$retval = 0;
		if(is_array($fileList)) {
			
			//connect to the database.
			$this->db = new cs_phpDB;
			$this->db->connect(array(
				'host'		=> 'crazedsanity.com',
				'dbname'	=> 'cs',
				'user'		=> 'postgres',
				'password'		=> '',
				'port'		=> '5432'
			));
			
			
			foreach($fileList as $name=>$data) {
				if($data['type'] == 'file') {
					$this->currentFile = $name;
					$xmlObj = new XMLParser($this->fs->read($name));
					$hitData = $xmlObj->get_tree(TRUE);
					$hitData = $hitData['HITSTRACKER'];
					
					//okay, pass the data along so it can be modified & used for an insert.
					try {
						$res = $this->handle_data($hitData);
						$this->gf->debug_print(__METHOD__ .": result of insert: (". $res .")");
						$retval += $res;
						
						$this->fs->rm($name);
					}
					catch(exception $e) {
						$this->gf->debug_print(__METHOD__ .": failed to handle data: ". $e->getMessage());
						exit;
					}
				}
				else {
					$this->gf->debug_print(__METHOD__ .": skipping invalid type (". $data['type'] ."): ". $name);
				}
			}
			$this->gf->debug_print(__METHOD__ .": result of handling (". count($fileList) .") files: (". $retval .")");
		}
		else {
			$this->gf->debug_print(__METHOD__ .": no files to parse");
		}
		
		return($retval);
	}//end process_all_files()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Handle data from a single file.
	 */
	private function handle_data(array $data) {
		
		$sqlArr = array();
		$sqlArr['source'] = $this->currentFile;
		
		foreach($data as $index=>$value) {
			switch($index) {
				case 'REFERER': {
					if(strlen($value)) {
						$sqlArr['page_id'] = $this->get_page_id($value);
						$sqlArr['url'] = $value;
					}
				}
				break;
				
				
				case 'REQUESTED_URL': {
					$sqlArr['cs_url'] = $value;
				}
				break;
				
				
				case 'USER_AGENT':
				case 'IP': {
					$sqlArr[strtolower($index)] = $value;
				}
				break;
				
				default: {
					throw new exception(__METHOD__ .": invalid index (". $index ."), value=(". $value .")");
				}
			}
		}
		
		return($this->run_the_insert($sqlArr));
	}//end handle_data()
	//=========================================================================



	//================================================================
	/**
	 * Retrieve the domain_id associated with the given string.
	 * 
	 * @param $domain		(string) domain or URL to retrieve domain from.
	 * 
	 * @return (int)		PASS: indicates the domain_id.
	 * @return (exception)	FAIL: exception indicates problem.
	 */
	private function get_domain_id($domain=NULL) {
		
		if(is_null($domain) || strlen($domain) < 3) {
			throw new exception(__METHOD__ .": invalid domain (". $domain .")");
		}
		elseif(preg_match('/tp:\/\//', $domain)) {
			$x = parse_url($domain);
			$domain = $x['host'];
			unset($x);
		}
		
		//let's find the domain.
		$query = "SELECT domain_id FROM ht_domain_table WHERE domain_name='". $domain ."'";
		$this->db->exec($query);
		$dberror = $this->db->errorMsg();
		$numRows = $this->db->numRows();
		
		if($dberror) {
			throw new exception(__METHOD__ .": failed to retrieve domain name");
			$retVal = $dberror;
		}
		elseif($numRows < 1) {
			$insert = "INSERT INTO ht_domain_table (domain_name) VALUES ('". $domain ."')";
			$this->db->exec($insert);
			
			if($this->db->errorMsg()) {
				throw new exception(__METHOD__ .": failed to run insert::: ". $this->db->errorMsg());
			}
			else {
				//grab the value we just inserted...
				$query = "SELECT currval('ht_domain_table_domain_id_seq')";
				$this->db->exec($query);
				$t = $this->db->frow();
				
				$retVal = $t[0];
			}
		}
		else {
			$t = $this->db->frow();
			$retVal = $t[0];
		}
		
		if(is_numeric($retVal)) {
			$this->domainId = $retVal;
		}
		
		return($retVal);
	}//end get_domain_id()
	//================================================================



	//================================================================
	/**
	 * Retrieve page_id associated with the given URL.
	 * 
	 * @param $url			(string) URL to parse for the page.
	 * 
	 * @return (int)		PASS: associated page_id.
	 * @return (exception)	FAIL: exception indicates problem.
	 */
	private function get_page_id($url=NULL) {
		
		//let's find the domain.
		if(strlen($url) && is_array(parse_url($url))) {
			$urlData = parse_url($url);
			
			$page = $urlData['path'];
			
			$domainId = $this->get_domain_id($urlData['host']);
			$sqlArr = array(
				'page_name'	=> $page,
				'domain_id'	=> $domainId
			);
			$query = "SELECT page_id FROM ht_page_table WHERE ". 
				$this->gf->string_from_array($sqlArr, 'select', NULL, 'sql');
			
			$this->db->exec($query);
			$dberror = $this->db->errorMsg();
			$numRows = $this->db->numRows();
	
			if($dberror) {
				throw new exception(__METHOD__ .": Failed to locate page::: ". $dberror);
			}
			elseif($numRows < 1) {
				$insert = "INSERT INTO ht_page_table (page_name, domain_id) " .
						"VALUES ('". $this->urlArr['path'] ."', ". $domainId ."	)";
				$this->db->exec($insert);
				
				if($this->db->errorMsg()) {
					throw new exception(__METHOD__ .": ERROR::: ". $this->db->errorMsg());
				}
				else {
					//grab the value we just inserted...
					$query = "SELECT currval('ht_page_table_page_id_seq')";
					$this->db->exec($query);
					$t = $this->db->frow();
					$retVal = $t[0];
				}
			}
			else {
				$t = $this->db->frow();
				$retVal = $t[0];
			}
			
			if(is_numeric($retVal)) {
				$this->pageId = $retVal;
			}
		}
		else {
			throw new exception(__METHOD__ .": no valid url given (". $url .")");
		}
		
		return($retVal);
	}//end get_page_id()
	//================================================================





	//================================================================
	/**
	 * Insert record into the database.
	 * 
	 * @param ($sqlArr)		(array) Data to insert into the db.
	 * 
	 * @return (int)		PASS: indicates number of rows inserted.
	 * @return (exception)	FAIL: exception indicates the problem.
	 */
	private function run_the_insert(array $sqlArr) {
		
		$insertStr = $this->gf->string_from_array($sqlArr, 'insert', NULL, 'sql');
		$insert = "INSERT INTO ht_hitstracker_table ". $insertStr;
		$this->db->exec($insert);
		$dberror = $this->db->errorMsg();
		$numRows = $this->db->numAffected();
		
		if($dberror) {
			$retVal = $dberror;
			#print "ERROR: $dberror || INSERT: $insert";
			$this->gf->debug_print($sqlArr,1);
			throw new exception(__METHOD__ .": Failed to insert record: ". $dberror ."<BR>\nSQL::: ". $insert);
		}
		else {
			$retVal = $numRows;
		}
		
		return($retVal);
	}//end run_the_insert()
	//================================================================
}

?>
