<?php
  //Make sure we're xml otherwise the phone will not parse correctly
  header('Content-type: text/xml');

  //Get the url of this page so we can do page forward/back requests
  $url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

  //Mode if we're searching by first, last or number
  $mode = '';

  if(isset($_GET['name']))
    $mode = 'name';
  else if(isset($_GET['number']))
    $mode = 'number';
  else{

    /* Nothing was searched for on this page so return an error via the 
     * CiscoIPPhoneText item */
    $error = showCiscoPhoneError('Search Error', 
      'Please check and try again', 
      'There was an error searching the address book, no search terms were set');

    echo $error -> asXML();
    exit(1);

  }

  $xml = showAddresses($mode, $url);

  echo $xml -> asXML();

  /**
   * Returns an error message in the form of a CiscoIPPhoneText XML snippet
   * @param $title: Title shown at top of phone screen
   * @param $prompt: Prompt shown at bottom of phone screen
   * @param $text: Text to show in middle of phone screen
   * @return Returns properly formatted XML snippet for Cisco 7942 and
   * compatible phones */
  function showCiscoPhoneError($title, $prompt, $text){

    $xml = new SimpleXMLElement('<CiscoIPPhoneText/>');
    $xml -> addChild('Title', $title);
    $xml -> addChild('Prompt', $prompt);
    $xml -> addChild('Text', $text);

    addSoftKey($xml, 'Exit', 'SoftKey:Exit', 1);

    return $xml;

  }

  /**
   * Adds a Cisco SoftKeyItem to the given XML object
   * @param $xml: SimpleXMLElement to act upon
   * @param $name: Name of the Key displayed on the phone (8 char limit)
   * @param $url: URL to call when key pressed
   *    Built in URLs:
   *        SoftKey:Exit - Exits
   *        SoftKey:Dial - Dials selected
   * @param $position: Position for soft key 1 - 4
   */
  function addSoftKey($xml, $name, $url, $position){

    $softKey = $xml -> addChild('SoftKeyItem');
    $softKey -> addChild('Name', $name);
    $softKey -> addChild('URL', $url);
    $softKey -> addChild('Position', $position);

  }

  //Reads the /etc/freepbx.conf and parses out the DB array config
  function getFreePBXDatabase(){

    $freepbx = file_get_contents('/etc/freepbx.conf');

    $lines = explode("\n", $freepbx);
    $DB = array();

    //for each line strip to VAR = "BLAH"; then keep the BLAH bit
    foreach($lines as $line){

      $parts = explode(' = ', $line);

      if($parts[0] == '$amp_conf["AMPDBUSER"]')
        $DB['AMPDBUSER'] = substr(str_replace('"', '', $parts[1]), 0, -1);
      else if($parts[0] == '$amp_conf["AMPDBPASS"]')
        $DB['AMPDBPASS'] = substr(str_replace('"', '', $parts[1]), 0, -1);
      else if($parts[0] == '$amp_conf["AMPDBHOST"]')
        $DB['AMPDBHOST'] = substr(str_replace('"', '', $parts[1]), 0, -1);
      else if($parts[0] == '$amp_conf["AMPDBNAME"]')
        $DB['AMPDBNAME'] = substr(str_replace('"', '', $parts[1]), 0, -1);

    }

    return $DB;

  }

  /** 
   * Queries FreePBX and produces xml suitable for Cisco 7942s
   * More Info and models available from Cisco: 
   * https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/all_models/xsi/8_5_1/xsi_dev_guide/xmlobjects.html
   */
  function showAddresses($mode, $url){

    $DB = getFreePBXDatabase();

    //Setup PDO connection and options
    $dsn = 'mysql:host=' . $DB['AMPDBHOST'] . ';dbname=' . $DB['AMPDBNAME'];

    $options = [
      PDO::ATTR_ERRMODE     => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES    => false,
    ];

    $pdo = NULL;

    //Connect to MySQL
    try {
      $pdo = new PDO($dsn, $DB['AMPDBUSER'], $DB['AMPDBPASS'], $options);
    } catch (\PDOException $e) {

      //Format Exception as XML
      return showCiscoPhoneError('MySQL Connect Error', 'Please inform IT', 
        'There was an error connecting to the MySQL database (' . $e->getCode() . '): ' . $e->getMessage());

    }

    $stmt = NULL;
    $query = NULL;

    switch($mode) {

      case 'number':
        $sql = 'SELECT name, extension FROM users WHERE extension LIKE :user
                UNION
                SELECT description AS name, grpnum AS extension FROM ringgroups WHERE grpnum LIKE :group
                ORDER BY extension, name';

        $query = '%' . $_GET['number'] . '%';
        
        break;

      case 'name':

        $sql = 'SELECT name, extension FROM users WHERE name LIKE :user
                UNION
                SELECT description AS name, grpnum AS extension FROM ringgroups WHERE description LIKE :group
                ORDER BY name, extension';

        $query = '%' . $_GET['name'] . '%';
        
        break;

    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$query, $query]);

    $extensions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if(sizeof($extensions) == 0){

      return showCiscoPhoneError('No results', 'Try again', 'No results found for ' . $query);

    } else {

      //We have results format as phonedirectory
      $xml = new SimpleXMLElement('<CiscoIPPhoneDirectory/>');
      $xml -> addChild('Title', 'Grace Academy');
      $xml -> addChild('Prompt', 'Dial selected');

      //Paginate results, 31 items max (need 1 result extra for next page item)
      $start = 0;
      $page = 0;

      if(isset($_GET['page']))
        $page = (int)$_GET['page'];

      $start = $page * 32;

      //Check to see if we need more pages
      $morePages = false;

      if(sizeof($extensions) > $start + 31)
        $morePages = true;

      $row = $start;

      while($row < sizeof($extensions) && $row < $start + 31){

        $entry = $xml -> addChild('DirectoryEntry');
        $entry -> addChild('Name', $extensions[$row]['name']);
        $entry -> addChild('Telephone', $extensions[$row]['extension']);
        $row++;

      }

      //Add the softkeys to the results
      addSoftKey($xml, 'Dial', 'SoftKey:Dial', 1);
      addSoftKey($xml, 'Exit', 'SoftKey:Exit', 2);

      //Check if we need a previous page button
      if($page > 0)
        addSoftKey($xml, 'Previous', 'SoftKey:Exit', 3);

      //Check if we need a next page button
      if($morePages){

        $query = str_replace('%', '', $query);
        //&amp; required as & not valid xml
        addSoftKey($xml, 'Next', $url . '?' . $mode . '=' . $query . '&amp;page=' . ++$page, 4);

      }

    }

    return $xml;

  }

?>