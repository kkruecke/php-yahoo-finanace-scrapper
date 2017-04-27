<?php declare(strict_types=1);	
namespace Yahoo;

class EarningsTable implements \IteratorAggregate, TableInterface {

   private   $dom;	
   private   $xpath;
   private   $trDOMNodeList;
   private   $row_count;
   private   $url;

   private $input_column_indecies;   // indecies of column names ordered in those names appear in config.xml  
   private $input_order = array();// Associative array of abbreviations mapped to indecies indicating their location in the table.

  public function __construct(\DateTime $date_time, array $column_names, array $output_ordering) 
  {
   /*
    * The column of the table that the external iterator should return
    */ 	  
    $opts = array(
                'http'=>array(
                  'method'=>"GET",
                  'header'=>"Accept-language: en\r\n" .  "Cookie: foo=bar\r\n")
                 );

    $context = stream_context_create($opts);

    $friendly_date = $date_time->format("m-d-Y");
 
    $this->url = self::make_url($date_time);
  
    $page = $this->get_html_file($this->url);
       
    $this->loadHTML($page); // also sets $this->dom and $this->xpath

    $this->loadRowNodes($this->xpath, $column_names, $output_ordering); 
  }

  private function loadHTML(string $page) : bool
  {
      $this->dom = new \DOMDocument();
     
      // load the html into the object
      $this->dom->strictErrorChecking = false; // default is true.
      
      // discard redundant white space
      $this->dom->preserveWhiteSpace = false;
  
      @$boolRc = $this->dom->loadHTML($page);  // Turn off error reporting
            
      $this->xpath = new \DOMXPath($this->dom);
      
      $extra_pages = $this->getTotalAdditionalPages($this->xpath);
      
      $this->table = $this->buildDOMTable($extra_pages);
            
      return $boolRc;
  } 
  
  private function getTotalAddtionalPages(\DOMXPath $xpath) : int
  {
      /* 
       * All these XPath queries work, starting with the most general at the top:
       *
       * //div[@id='fin-cal-table']                            find any div whose id is 'fin-cal-table'
       * //div[@id='fin-cal-table']//span                      After find that div, find any spans anywhere under it
       * //div[@id='fin-cal-table']//span[text()='1-100 of 1169 results']      ...that have the specified text    
       * //div[@id='fin-cal-table']//span[contains(text(), 'results')]         ...that contain the specified text   
       *
       *  The last query above is the one we really want: 
       */
      $nodeList = $xpath->query("//div[@id='fin-cal-table']//span[contains(text(), 'results')]"); // works--best
            
      if ($nodeList->length == 0) { // div was not found.
      
          //TODO: Throw an exception explaining the error.
      }    
          
      $nodeElement = $nodeList->item(0);
          
      preg_match("/(\d+) results/", $nodeElement->nodeValue, $matches);
      
      $earning_results = (int) ($matches[1]);
      
      return floor($earning_results/100);
  } 

  function loadRowNodes(\DOMXPath $xpath, array $column_names, array $output_ordering)
  {
      $nodeList = $xpath->query("(//table)[2]");
      
      if ($nodeList->length == 0) { // Table was not found.

          // Set default values if there is no data on the page          
          $this->row_count = 0;

          // set some default values since there is no table on the page.
          $total = count($output_ordering);
 
          $this->input_order = array_combine(array_keys($output_ordering), range(0, $total - 1));
          return;
      }

      $tblElement = $nodeList->item(0);

      $this->trDOMNodeList = $this->getChildNodes($xpath->query("tbody", $tblElement)); //--$nodeList);
      
      $this->row_count = $this->trDOMNodeList->length;

      $this->findColumnIndecies($xpath, $tblElement, $column_names, $output_ordering);
    
      $this->createInputOrdering($output_ordering);
  } 

  /*
   *  Input:
   *  $column_names = Configuration::config('column-column_names') 
   *  $output_ordering  =  Configuration::config('output-order') 
   */ 
  private function findColumnIndecies(\DOMXPath $xpath, \DOMElement $DOMElement, $column_names, $output_ordering) 
  {  
     $config_col_cnt = count($column_names);

     $thNodelist = $xpath->query("thead/tr/th", $DOMElement);

     $arr = array();
     
     $col_num = 0; 
     
     do {
 
        $thNode = $thNodelist->item($col_num);
        
        $index = array_search($thNode->nodeValue, $column_names); 
                
        if ($index !== FALSE) {
            
            $this->input_column_indecies[] = $col_num; 
        } 

        if (++$col_num == $thNodelist->length) { // If no more columns to examine...
            
            if (count($this->input_column_indecies) != $config_col_cnt) { // ... if we didn't find all the $column_names
                
                throw new Exception("One or more column names specificied in config.xml were not found in the earning's table's column headers.");
            }
            break;    
        }
        
     } while(count($this->input_column_indecies) < $config_col_cnt);
  }
  
  private function createInputOrdering(array $output_ordering)
  {
     $abbrevs = array_keys($output_ordering);
    
    for($i = 0; $i < count($abbrevs); ++$i) { // we ignore output_input
      
       $this->input_order[$abbrevs[$i]] = $this->input_column_indecies[$i];
    }
  }

  public function getInputOrder() : array
  {
    return $this->input_order;
  }
  /*
    Input: abbrev from confg.xml
    Output: Its index in the returned getRowData() 
   */
  public function getRowDataIndex(string $abbrev) : int
  {
    // If it is a valid $abbrev, return its index.  
    return array_search($abbrev, $this->input_order) !== false ? $this->input_order[$abbrev] : false;
  }
   
  function getChildNodes(\DOMNodeList $NodeList)  : \DOMNodeList // This might not be of use.
  {
      if ($NodeList->length != 1) { 
         
          throw new \Exception("DOMNodeList length is not one.\n");
      } 
      
      // Get DOMNode representing the table. 
      $DOMElement = $NodeList->item(0);
      
      if (!$DOMElement->hasChildNodes()) {
         
         throw new \Exception("hasChildNodes() failed.\n");
      } 
  
      // DOMNodelist for rows of the table
      return $DOMElement->childNodes;
  }

  /*
   * returns SplFixedArray of all text for the columns indexed by $this->input_column_indecies in row number $row_num
   */ 
  public function getRowData($row_num) : \SplFixedArray
  {
     $row_data = new \SplFixedArray(count($this->input_column_indecies));
 
     // get DOMNode for row number $row_id
     $rowNode =  $this->trDOMNodeList->item($row_num);

     // get DOMNodeList of <td></td> elemnts in the row     
     $tdNodelist = $rowNode->getElementsByTagName('td');

     $i = 0;

     foreach($this->input_column_indecies as $index) {

         $td = $tdNodelist->item($index);     

         $nodeValue = trim($td->nodeValue);
        
         $row_data[$i++] = html_entity_decode($nodeValue);

     }
     return $row_data;
  }
  
  static public function page_exists(\DateTime $date_time) : bool
  {
    return self::url_exists( self::make_url($date_time) );
  }

  static private function url_exists(string $url) : bool
  {
    $file_headers = @get_headers($url);

    if(strpos($file_headers[0], '404 Not Found') !== false) {

        return false;

    } else {

      return true;

    }
  } 

  static private function make_url(\DateTime $date_time) : string
  {
        return Configuration::config('url') . '?day=' . $date_time->format('Y-m-d');
  }

  private function get_html_file(string $url) : string
  {
      for ($i = 0; $i < 2; ++$i) {
          
         $page = @file_get_contents($url, false, $context);
                 
         if ($page !== false) {
             
            break;
         }   
         
         echo "Attempt to download data for $friendly_date on webpage $url failed. Retrying.\n";
      }
      
      if ($i == 2) {
          
         throw new \Exception("Could not download page $url after two attempts\n");
      }

      return $page;
  } 
    
  /*
  * Return external iterator, passing the range of columns requested.
  */ 
  public function getIterator() : \Yahoo\EarningsTableIterator
  {
     return new EarningsTableIterator($this);
  }

  public function row_count() : int
  {
     return $this->row_count;
  } 

  public function column_count() : int
  {
     return count($this->input_column_indecies);
  }
} 
