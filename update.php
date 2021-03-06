<?php

// This is where most of the magic happens. 
// update.php checks all projects it knows about and logs their current dev releases to the `versions` table

header("Content-type: text/plain");

include('_lib.php');

  $count = 0;
  print "begin...\r\n\r\n";

  // Clear the database of old dev versions.
  // There's no reason to keep a record of past releases. Drupal's version control does that.
  // This allows us to stay current in cases where the maintainer removes or promotes a development release.
  $cleanup = sprintf("DELETE FROM `versions` WHERE timestamp < NOW(); ");
  // mysql_query($cleanup) or die(mysql_error());





  // pull projects
  $projects = fetchContrib();

/*
  // debug
  $psql = sprintf("SELECT * FROM `projects` WHERE `type` = 'module' LIMIT 10; ");
  $projects = mysql_query($psql);
//*/

  while($p = mysql_fetch_assoc($projects)){
  
    $count++;
    
    // get some key data out of each module's .info file
    // drush knows what the current recommended versions
    
    // fetching recommended version information
    $cmd = "cd d".$version."; ".PATH_TO_DRUSH." pm-releases ".$p['unique']." | grep 'Recommended'";
    $result = trim(`$cmd`);

    // debug 
    print "\r\ncmd:\r\n   ".$cmd."\r\n\r\nresult:\r\n   ".$result."\r\n";

    // pull stable version nubmer    
    preg_match('/ '.$version.'\.x-(\d{1}\.\d*)/',$result,$recVersion);
    $stableVersion = 'DRUPAL-'.$version.'--'.str_replace('.','-',$recVersion[1]);
    preg_match('/DRUPAL-'.$version.'--\d{1}/',$stableVersion,$shortVersion);

    // debug
    print "debug:\r\n   Recommended Version: ".$recVersion[0]."| DRUPAL-".$version."--".$recVersion[1]."\r\n\r\n";
    
    // fetch the latest stable release
    // modules
    $url1 = 'http://drupalcode.org/viewvc/drupal/contributions/modules/'.$p['unique'].'/'.$p['unique'].'.info?view=co&pathrev='.$stableVersion;
    $url2 = 'http://drupalcode.org/viewvc/drupal/contributions/modules/'.$p['unique'].'/'.$p['unique'].'.info?view=co&pathrev='.$shortVersion[0];
    // themes
    $url3 = 'http://drupalcode.org/viewvc/drupal/contributions/themes/'.$p['unique'].'/'.$p['unique'].'.info?view=co&pathrev='.$stableVersion;
    $url4 = 'http://drupalcode.org/viewvc/drupal/contributions/themes/'.$p['unique'].'/'.$p['unique'].'.info?view=co&pathrev='.$shortVersion[0];

    print "urls:\r\n";
    print '   '.$url1."\r\n";
    print '   '.$url2."\r\n\r\n";
    print '   '.$url3."\r\n";
    print '   '.$url4."\r\n\r\n";
    $packageName = $dependencies = '';

    // try all 4 urls. this is so wasteful and it is not a permanent solution.
    // version numbers are picked by human maintainers so we have to keep guessing if we want more complete auto-population :\
    // furthermore, some .info files don't even match the project name (the URL you go to on d.o) and they all seem to be high profile, oft-used projects.

    $ch = curl_init($url1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $moduleInfo1 = curl_exec($ch);       
    curl_close($ch);

    $ch = curl_init($url2);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $moduleInfo2 = curl_exec($ch);       
    curl_close($ch);
    
    $ch = curl_init($url3);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $themeInfo1 = curl_exec($ch);       
    curl_close($ch);
    
    $ch = curl_init($url4);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $themeInfo2 = curl_exec($ch);       
    curl_close($ch);
    
    // See if we found the .info file;
    // Try the second URL if the first failed;

    $moduleInfo = FALSE;
    $themeInfo = FALSE;
    
    // IF ANYONE HAS A BETTER METHOD FOR THIS (I.E. A REAL METHOD) PLEASE LET ME KNOW!!! :)
    
    // + select either moduleInfo 1 or 2 and assign to the unnumbered variable
    if (preg_match('/name.*?=/',$moduleInfo1)) {$moduleInfo = $moduleInfo1; }
    elseif (preg_match('/name.*?=/',$moduleInfo2)) {$moduleInfo = $moduleInfo2; }
    
    // + do the same for themeInfo 1 and 2
    if (preg_match('/name.*?=/',$themeInfo1)) {$themeInfo = $themeInfo1; }
    elseif (preg_match('/name.*?=/',$themeInfo2)) {$themeInfo = $themeInfo2; }

    /*
    // debug    
    print "\r\nMODULEINFO\r\n".$moduleInfo;
    print "\r\nTHEMEINFO\r\n".$themeInfo;
    //*/
    
    
    
    
    if ($moduleInfo) {

      // now we parse the .info file for the goods
      print "   -- module .info file found";
      
      // parse output of .info file for package
      preg_match('/package\s+=\s+\"?([^\"\n]*)\"?/',$moduleInfo,$package);
      $packageName = ($package[1]) ? str_replace('\'','',$package[1]) : 'Other';
  
      // parse output of .info file for dependencies. regex is simpler because project uniques are lowercase_with_underscores
      preg_match_all('/dependencies\[\]\s+=\s+(.*)/', $moduleInfo, $d);
      $dependencies = serialize($d[1]);
      
      // update main module info in our tables
      $updateSQL = sprintf(
        "UPDATE `projects` SET ".
        "package = '%s', dependencies = '%s', `type` = 'module' ".
        "WHERE id = %d; ",
        $packageName, str_replace('\'','',$dependencies), 
        $p['id']
        );
      print "\r\n\r\n   ".$updateSQL;
      mysql_query($updateSQL) or die(mysql_error());
      
      
      // fetching dev version information
      $cmd = "cd d".$version."; ".PATH_TO_DRUSH." pm-releases ".$p['unique']." | grep 'Supported\|Development' | grep -v 'Recommended'";
      $result = trim(`$cmd`);
      
      print "\r\n   ".$cmd."\r\n\r\n ".$result."\r\n";
      $releases = explode("\n",$result);
      $sql = '';
      print "\r\n";
      foreach($releases as $r){
        preg_match('/ '.$version.'\.(.*?) /',$r,$match);
        $dev = trim($match[0]);
        $sql = sprintf("INSERT INTO `versions` (`id`,`pid`,`version`,`release`) VALUES ('',%d,'%s','%s'); ",$p['id'],$version,$dev);
        mysql_query($sql) or die(mysql_error());
        print '   '.$sql."\r\n";
      }
      print "\r\n";
      
    }
    else if ($themeInfo) {

      // now we parse the .info file for the goods
      print "   -- theme .info file found";
      
      // parse output of .info file for package
      preg_match('/package\s+=\s+\"?([^\"\s\n]*)\"?/',$moduleInfo,$package);
      $packageName = ($package[1]) ? str_replace('\'','',$package[1]) : 'Other';
  
      // parse output of .info file for dependencies
      preg_match_all('/dependencies\[\]\s+=\s+(.*)/', $moduleInfo, $d);
      $dependencies = serialize($d[1]);
      
      // update main module info in our tables
      $updateSQL = sprintf(
        "UPDATE `projects` SET ".
        "package = '%s', dependencies = '%s', `type` = 'theme' ".
        "WHERE id = %d; ",
        $packageName, str_replace('\'','',$dependencies), 
        $p['id']
        );
      print "\r\n\r\n   ".$updateSQL;
      mysql_query($updateSQL) or die(mysql_error());
      
      
      // fetching dev version information
      $cmd = "cd d".$version."; ".PATH_TO_DRUSH." pm-releases ".$p['unique']." | grep 'Supported\|Development' | grep -v 'Recommended'";
      $result = trim(`$cmd`);
      
      print "\r\n   ".$cmd."\r\n\r\n ".$result."\r\n";
      $releases = explode("\n",$result);
      $sql = '';
      print "\r\n";
      foreach($releases as $r){
        preg_match('/ '.$version.'\.(.*?) /',$r,$match);
        $dev = trim($match[0]);
        $sql = sprintf("INSERT INTO `versions` (`id`,`pid`,`version`,`release`) VALUES ('',%d,'%s','%s'); ",$p['id'],$version,$dev);
        mysql_query($sql) or die(mysql_error());
        print '   '.$sql."\r\n";
      }
      print "\r\n";
      
    }
    else if (strpos($moduleInfo,'InvalidRevision:')) {
      // Requested version of Drupal doesn't support this module
      print "   ========== Not supported in Drupal ".$version."\r\n\r\n";
    }
    else {
      // Couldn't recognize file, don't do anything
      print "   ========== I have no idea what's going on with this one...\r\n\r\n";
    }
    
    // debug
    print '=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-'."\r\n\r\n";
  
  } // end while

  print "\r\n     ...end\r\n\r\n";
  print $count.' projects updated'."\r\n";
  
?>