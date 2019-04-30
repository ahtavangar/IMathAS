<?php

namespace IMathAS\assess2\questions\scorepart;

require_once(__DIR__ . '/ScorePart.php');

use IMathAS\assess2\questions\models\ScoreQuestionParams;

class NTupleScorePart implements ScorePart
{
    private $scoreQuestionParams;

    public function __construct(ScoreQuestionParams $scoreQuestionParams)
    {
        $this->scoreQuestionParams = $scoreQuestionParams;
    }

    public function getScore(): int
    {
        global $mathfuncs;

        $RND = $this->scoreQuestionParams->getRandWrapper();
        $options = $this->scoreQuestionParams->getVarsForScorePart();
        $qn = $this->scoreQuestionParams->getQuestionNumber();
        $givenans = $this->scoreQuestionParams->getGivenAnswer();
        $multi = $this->scoreQuestionParams->getIsMultiPartQuestion();
        $partnum = $this->scoreQuestionParams->getQuestionPartNumber();

        $defaultreltol = .0015;

        if (is_array($options['answer'])) {$answer = $options['answer'][$partnum];} else {$answer = $options['answer'];}
        if (isset($options['reltolerance'])) {if (is_array($options['reltolerance'])) {$reltolerance = $options['reltolerance'][$partnum];} else {$reltolerance = $options['reltolerance'];}}
        if (isset($options['abstolerance'])) {if (is_array($options['abstolerance'])) {$abstolerance = $options['abstolerance'][$partnum];} else {$abstolerance = $options['abstolerance'];}}
        if (isset($options['answerformat'])) {if (is_array($options['answerformat'])) {$answerformat = $options['answerformat'][$partnum];} else {$answerformat = $options['answerformat'];}}
        if (isset($options['requiretimes'])) {if (is_array($options['requiretimes'])) {$requiretimes = $options['requiretimes'][$partnum];} else {$requiretimes = $options['requiretimes'];}}
        if (isset($options['scoremethod'])) {if (is_array($options['scoremethod'])) {$scoremethod = $options['scoremethod'][$partnum];} else {$scoremethod = $options['scoremethod'];}}
        if (isset($options['ansprompt'])) {if (is_array($options['ansprompt'])) {$ansprompt = $options['ansprompt'][$partnum];} else {$ansprompt = $options['ansprompt'];}}

        if (!isset($reltolerance) && !isset($abstolerance)) { $reltolerance = $defaultreltol;}
        if (!isset($scoremethod)) {	$scoremethod = 'whole';	}
        if ($multi) { $qn = ($qn+1)*1000+$partnum; }
        if (!isset($answerformat)) { $answerformat = '';}
        $givenans = normalizemathunicode($givenans);
        $givenans = str_replace(array('(:',':)','<<','>>'), array('<','>','<','>'), $givenans);

        $ansformats = array_map('trim',explode(',',$answerformat));
        $answer = str_replace(' ','',$answer);

        if (in_array('nosoln',$ansformats) || in_array('nosolninf',$ansformats)) {
            list($givenans, $_POST["tc$qn"], $answer) = scorenosolninf($qn, $givenans, $answer, $ansprompt);
        }

        if ($anstype=='ntuple') {
            $GLOBALS['partlastanswer'] = $givenans;
        } else if ($anstype=='calcntuple') {
            // parse and evaluate
            if ($hasNumVal) {
                $gaarr = parseNtuple($givenansval, false, true);
                $GLOBALS['partlastanswer'] = $givenans.'$#$'.$givenansval;
            } else {
                $gaarr = parseNtuple($givenans, false, true);
                $GLOBALS['partlastanswer'] = $givenans.'$#$'.ntupleToString($gaarr);
            }
            //test for correct format, if specified
            if (checkreqtimes($givenans,$requiretimes)==0) {
                return 0;
            }

            //parse the ntuple without evaluating
            $tocheck = parseNtuple($givenans, false, false);

            if ($answer != 'DNE' && $answer != 'oo') {
                foreach($tocheck as $chkme) {
                    foreach ($chkme['vals'] as $chkval) {
                        if ($chkval != 'oo' && $chkval != '-oo') {
                            if (!checkanswerformat($chkval,$ansformats)) {
                                return 0; //perhaps should just elim bad answer rather than all?
                            }
                        }
                    }
                }
            }
        }
        if ($givenans == null) {return 0;}

        $givenans = str_replace(' ','',$givenans);

        if ($answer=='DNE') {
            if (strtoupper($givenans)=='DNE') {
                return 1;
            } else {
                return 0;
            }
        } else if ($answer=='oo') {
            if ($givenans=='oo') {
                return 1;
            } else {
                return 0;
            }
        }

        if (count($gaarr)==0) {
            return 0;
        }

        $answer = makepretty($answer);
        // parse and evaluate the answer, capturing "or"s
        $anarr = parseNtuple($answer, true, true);


        if (in_array('scalarmult',$ansformats)) {
            //normalize the vectors
            foreach ($anarr as $k=>$listans) {
                foreach ($listans as $ork=>$orv) {
                    $mag = sqrt(array_sum(array_map(function($x) {return $x*$x;}, $orv['vals'])));
                    foreach ($orv['vals'] as $j=>$v) {
                        if (abs($v)>1e-10) {
                            if ($v<0) {
                                $mag *= -1;
                            }
                            break;
                        }
                    }
                    if (abs($mag)>0) {
                        foreach ($orv['vals'] as $j=>$v) {
                            $anarr[$k][$ork]['vals'][$j] = $v/$mag;
                        }
                    }
                }
            }
            foreach ($gaarr as $k=>$givenans) {
                $mag = sqrt(array_sum(array_map(function($x) {return $x*$x;}, $givenans['vals'])));
                foreach ($givenans['vals'] as $j=>$v) {
                    if (abs($v)>1e-10) {
                        if ($v<0) {
                            $mag *= -1;
                        }
                        break;
                    }
                }
                if (abs($mag)>0) {
                    foreach ($givenans['vals'] as $j=>$v) {
                        $gaarr[$k]['vals'][$j] = $v/$mag;
                    }
                }
            }
        }

        $gaarrcnt = count($gaarr);
        $extrapennum = count($gaarr)+count($anarr);
        $correct = 0;
        $partialmatches = array();
        $matchedans = array();
        $matchedgivenans = array();
        foreach ($anarr as $ai=>$ansors) {
            $foundloc = -1;
            foreach ($ansors as $answer) {  //each of the "or" options
                foreach ($gaarr as $j=>$givenans) {
                    if (isset($matchedgivenans[$j])) {continue;}

                    if ($answer['lb']!=$givenans['lb'] || $answer['rb']!=$givenans['rb']) {
                        break;
                    }

                    if (count($answer['vals'])!=count($givenans['vals'])) {
                        break;
                    }
                    $matchedparts = 0;
                    foreach ($answer['vals'] as $i=>$ansval) {
                        $gansval = $givenans['vals'][$i];
                        if (is_numeric($ansval) && is_numeric($gansval)) {
                            if (isset($abstolerance)) {
                                if (abs($ansval-$gansval) < $abstolerance + 1E-12) {
                                    $matchedparts++;
                                }
                            } else {
                                if (abs($ansval-$gansval)/(abs($ansval)+.0001) < $reltolerance+ 1E-12) {
                                    $matchedparts++;
                                }
                            }
                        } else if (($ansval=='oo' && $gansval=='oo') || ($ansval=='-oo' && $gansval=='-oo')) {
                            $matchedparts++;
                            //is ok
                        }
                    }

                    if ($matchedparts==count($answer['vals'])) { //if totally correct
                        $correct += 1; $foundloc = $j; break 2;
                    } else if ($scoremethod=='byelement' && $matchedparts>0) { //if partially correct
                        $fraccorrect = $matchedparts/count($answer['vals']);
                        if (!isset($partialmatches["$ai-$j"]) || $fraccorrect>$partialmatches["$ai-$j"]) {
                            $partialmatches["$ai-$j"] = $fraccorrect;
                        }
                    }
                }
            }
            if ($foundloc>-1) {
                //array_splice($gaarr,$foundloc,1); // remove from list
                $matchedgivenans[$foundloc] = 1;
                $matchedans[$ai] = 1;
                if (count($gaarr)==count($matchedgivenans)) {
                    break;
                }
            }
        }
        if ($scoremethod=='byelement') {
            arsort($partialmatches);
            foreach ($partialmatches as $k=>$v) {
                $kp = explode('-', $k);
                if (isset($matchedans[$kp[0]]) || isset($matchedgivenans[$kp[1]])) {
                    //already used this ans or stuans
                    continue;
                } else {
                    $correct += $v;
                    $matchedans[$kp[0]] = 1;
                    $matchedgivenans[$kp[1]] = 1;
                    if (count($gaarr)==count($matchedgivenans)) {
                        break;
                    }
                }
            }
        }
        if ($gaarrcnt<=count($anarr)) {
            $score = $correct/count($anarr);
        } else {
            $score = $correct/count($anarr) - ($gaarrcnt-count($anarr))/$extrapennum;  //take off points for extranous stu answers
        }
        //$score = $correct/count($anarr) - count($gaarr)/$extrapennum;
        if ($score<0) { $score = 0; }
        return ($score);
    }
}