<?php

require_once(__DIR__."/copyiteminc.php");

// TODO: Revamp this total hack job.
// Rewrite the item and course copying as a class

function copycourse($sourcecid, $name) {
  global $DBH, $CFG, $imasroot, $defaultcoursetheme, $userid, $myrights, $groupid;
  global $copystickyposts, $gbcats, $replacebyarr, $datesbylti, $convertAssessVer;
  global $removewithdrawn, $usereplaceby, $cid;

  $blockcnt = 1;
  $itemorder = serialize(array());
  $randkey = uniqid();
  $hideicons = isset($CFG['CPS']['hideicons'])?$CFG['CPS']['hideicons'][0]:0;
  $picicons = isset($CFG['CPS']['picicons'])?$CFG['CPS']['picicons'][0]:0;
  $allowunenroll = isset($CFG['CPS']['allowunenroll'])?$CFG['CPS']['allowunenroll'][0]:0;
  $copyrights = isset($CFG['CPS']['copyrights'])?$CFG['CPS']['copyrights'][0]:0;
  $msgset = isset($CFG['CPS']['msgset'])?$CFG['CPS']['msgset'][0]:0;
  $msgmonitor = (floor($msgset/5))&1;
  $msgset = $msgset%5;
  if (!isset($defaultcoursetheme)) {$defaultcoursetheme = "modern.css";}
  $theme = isset($CFG['CPS']['theme'])?$CFG['CPS']['theme'][0]:$defaultcoursetheme;
  $showlatepass = isset($CFG['CPS']['showlatepass'])?$CFG['CPS']['showlatepass'][0]:0;

  $avail = 0;
  $lockaid = 0;
  $DBH->beginTransaction();

  $query = "INSERT INTO imas_courses (name,ownerid,enrollkey,hideicons,picicons,allowunenroll,copyrights,msgset,showlatepass,itemorder,available,theme,ltisecret,blockcnt) VALUES ";
  $query .= "(:name,:ownerid,:enrollkey,:hideicons,:picicons,:allowunenroll,:copyrights,:msgset,:showlatepass,:itemorder,:available,:theme,:ltisecret,:blockcnt)";
  $stm = $DBH->prepare($query);
  $stm->execute(array(':name'=>$name, ':ownerid'=>$userid, ':enrollkey'=>$randkey, ':hideicons'=>$hideicons, ':picicons'=>$picicons,
    ':allowunenroll'=>$allowunenroll, ':copyrights'=>$copyrights, ':msgset'=>$msgset, ':showlatepass'=>$showlatepass, ':itemorder'=>$itemorder,
    ':available'=>$avail, ':theme'=>$theme, ':ltisecret'=>$randkey, ':blockcnt'=>$blockcnt));
  $destcid = $DBH->lastInsertId();

  //call hook, if defined
  if (function_exists('onAddCourse')) {
    onAddCourse($destcid, $userid, $myrights, $groupid);
  }

  $stm = $DBH->prepare('INSERT INTO imas_teachers (userid,courseid) VALUES (:userid,:destcid)');
  $stm->execute(array(':userid'=>$userid, ':destcid'=>$destcid));

  //copy gbscheme
  $query = "SELECT useweights,orderby,defaultcat,defgbmode,stugbmode,usersort FROM imas_gbscheme WHERE courseid=:courseid";
  $stm = $DBH->prepare($query);
  $stm->execute(array(':courseid'=>$sourcecid));
  $row = $stm->fetch(PDO::FETCH_NUM);

  $stm = $DBH->prepare("INSERT INTO imas_gbscheme (courseid,useweights,orderby,defaultcat,defgbmode,stugbmode,usersort) VALUES (:courseid, :useweights, :orderby, :defaultcat, :defgbmode, :stugbmode, :usersort)");
  $stm->execute(array(':courseid'=>$destcid, ':useweights'=>$row[0], ':orderby'=>$row[1], ':defaultcat'=>$row[2], ':defgbmode'=>$row[3], ':stugbmode'=>$row[4], ':usersort'=>$row[5]));

  // copy gbcats
  $gbcats = array();
  $stm = $DBH->prepare("SELECT id,name,scale,scaletype,chop,dropn,weight,hidden,calctype FROM imas_gbcats WHERE courseid=:courseid");
  $stm->execute(array(':courseid'=>$sourcecid));

  $query = "INSERT INTO imas_gbcats (courseid,name,scale,scaletype,chop,dropn,weight,hidden,calctype) VALUES ";
  $query .= "(:courseid,:name,:scale,:scaletype,:chop,:dropn,:weight,:hidden,:calctype)";
  $cols = explode(',', ':courseid,:name,:scale,:scaletype,:chop,:dropn,:weight,:hidden,:calctype');
  $stm2 = $DBH->prepare($query);

  while ($row = $stm->fetch(PDO::FETCH_NUM)) {
    $frid = $row[0];
    $row[0] = $destcid; //change course id

    $varmap = array();
    foreach ($cols as $i=>$col) {
      $varmap[$col] = $row[$i];
    }
    $stm2->execute($varmap);
    $gbcats[$frid] = $DBH->lastInsertId();
  }
  $copystickyposts = true;
  $stm = $DBH->prepare("SELECT itemorder,ancestors,outcomes,latepasshrs,dates_by_lti,deflatepass,UIver,level FROM imas_courses WHERE id=:id");
  $stm->execute(array(':id'=>$sourcecid));
  $r = $stm->fetch(PDO::FETCH_NUM);

  $items = unserialize($r[0]);
  $ancestors = $r[1];
  $outcomesarr = $r[2];
  $latepasshrs = $r[3];
  $datesbylti = $r[4];
  $deflatepass = $r[5];
  $sourceUIver = $r[6];
  $courselevel = $r[7];
  if (isset($_POST['usenewassess'])) {
    $destUIver = 2;
    $convertAssessVer = 2;
  } else {
    $destUIver = $sourceUIver;
  }
  if ($ancestors=='') {
    $ancestors = intval($sourcecid);
  } else {
    $ancestors = intval($sourcecid).','.$ancestors;
  }
  $ancestors = $ancestors;
  $outcomes = array();
  $query = 'SELECT imas_questionset.id,imas_questionset.replaceby FROM imas_questionset JOIN ';
  $query .= 'imas_questions ON imas_questionset.id=imas_questions.questionsetid JOIN ';
  $query .= 'imas_assessments ON imas_assessments.id=imas_questions.assessmentid WHERE ';
  $query .= "imas_assessments.courseid=:courseid AND imas_questionset.replaceby>0";
  $stm = $DBH->prepare($query);
  $stm->execute(array(':courseid'=>$sourcecid));
  while ($row = $stm->fetch(PDO::FETCH_NUM)) {
    $replacebyarr[$row[0]] = $row[1];
  }

  if ($outcomesarr!='') {
    $stm = $DBH->prepare("SELECT id,name,ancestors FROM imas_outcomes WHERE courseid=:courseid");
    $stm->execute(array(':courseid'=>$sourcecid));

    $stm2 = $DBH->prepare("INSERT INTO imas_outcomes (courseid,name,ancestors) VALUES (:destcid,:name,:ancestors)");

    while ($row = $stm->fetch(PDO::FETCH_NUM)) {
      if ($row[2]=='') {
        $row[2] = $row[0];
      } else {
        $row[2] = $row[0].','.$row[2];
      }
      $stm2->execute(array(':destcid'=>$destcid, ':name'=>$row[1], ':ancestors'=>$row[2]));
      $outcomes[$row[0]] = $DBH->lastInsertId();
    }
    function updateoutcomes(&$arr) {
      global $outcomes;
      foreach ($arr as $k=>$v) {
        if (is_array($v)) {
          updateoutcomes($arr[$k]['outcomes']);
        } else {
          $arr[$k] = $outcomes[$v];
        }
      }
    }
    $outcomesarr = unserialize($outcomesarr);
    updateoutcomes($outcomesarr);
    $newoutcomearr = serialize($outcomesarr);
  } else {
    $newoutcomearr = '';
  }
  $removewithdrawn = true;
  $usereplaceby = "all";
  $newitems = array();
  $cid = $destcid; //needed for copyiteminc
  copyallsub($items,'0',$newitems,$gbcats);
  doaftercopy($sourcecid, $newitems);

  $itemorder = serialize($newitems);
  $stm = $DBH->prepare("UPDATE imas_courses SET itemorder=:itemorder,blockcnt=:blockcnt,ancestors=:ancestors,outcomes=:outcomes,latepasshrs=:latepasshrs,deflatepass=:deflatepass,dates_by_lti=:datesbylti,UIver=:UIver,level=:level WHERE id=:id");
  $stm->execute(array(':itemorder'=>$itemorder, ':blockcnt'=>$blockcnt, ':ancestors'=>$ancestors, ':outcomes'=>$newoutcomearr, ':latepasshrs'=>$latepasshrs, ':deflatepass'=>$deflatepass, ':datesbylti'=>$datesbylti, ':UIver'=>$destUIver, ':level'=>$courselevel, ':id'=>$destcid));

  $offlinerubrics = array();
  copyrubrics();
  $DBH->commit();

  return $destcid;
}
