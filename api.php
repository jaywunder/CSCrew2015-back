<?php
require_once('vendor/autoload.php');
require_once('functions.php');

define("MAX_IMAGE_WIDTH", 512);
define("MAX_IMAGE_HEIGHT", 512);

$app = new \Slim\Slim();

function render_json($data) {
    $app = \Slim\Slim::getInstance();
    $app->response->headers->set('Content-Type', 'application/json');
    $app->response->headers->set('Access-Control-Allow-Origin', '*');
    $app->response->setBody( json_encode($data, JSON_NUMERIC_CHECK) . "\n");
    $app->stop;
}

function render_json_string($data) {
    $app = \Slim\Slim::getInstance();
    $app->response->headers->set('Content-Type', 'application/json');
    $app->response->headers->set('Access-Control-Allow-Origin', '*');
    $app->response->setBody( $data . "\n");
    $app->stop;
}

function require_authenticated($who = false, $requireNetid = null, $adminOk = false) {
    if (!$who) {
        $who = get_loggedin_info();
    }
    if ($who['authenticated']) {
        // If a specific netid is required, check and see if it matches.
        if ($requireNetid != null) {
            if ($requireNetid == $who['username']) {
                return true;
            }
            elseif ($adminOk) {
                return $who['user']['IsAdmin'];
            }
        }
        return true;
    }

    $app = \Slim\Slim::getInstance();
    $app->response->status(401);
    render_json(array("error"=>true,"message"=>"This endpoint requires authentication."));
    return false;
}
function require_admin($who = false) {
    if (!$who) {
        $who = get_loggedin_info();
    }

    require_authenticated($who); // Pass $who as a parameter so it doesn't need to be requested again.
    if (array_key_exists("user",$who)) {
        if ($who['user']['IsAdmin']) {
            return true;
        }
    }
    // If any of those if statements above fail, and the function does not return, the user must not be an admin.
    $app = \Slim\Slim::getInstance();
    $app->response->setStatus(401);
    render_json(array("error"=>true,"message"=>"Endpoint is for administrators only."));
    return false;
}

$app->get('/netid-info/:netid', function($netid) use($app) {
    render_json(netid_info($netid));
});
$app->get('/netid-db/:netid', function($netid) use ($app) {
    render_json_string(get_user($netid)->toJSON());
});
$app->get('/signin/:netid/:reason', function($netid, $reason) use ($app) {
    $result = signin_netid($netid, $reason);
    render_json($result);
});
$app->group('/signins', function() use ($app) {
    $app->get('/today', function() use ($app) {
        render_json(signins_today());
    });
    $app->get('/all', function() use ($app) {
        if (!require_admin()) {return;}
        render_json(signins_all());
    });
    $app->get('/reasons', function() use ($app) {
        $reasons = signInReasonQuery::create()->find();
        render_json($reasons->toArray());
    });
    $app->get('/stats', function() use ($app) {
        $beginOfDay = strtotime("midnight", time());
        $stats = array();
        $stats['signinsToday'] = signInQuery::create()->filterByCreatedAt(array('min'=>$beginOfDay))->count();
        $stats['uniqueUsers'] = UserQuery::create()->count();

        render_json($stats);
    });
});
$app->group('/users', function() use ($app) {
    $app->get('/list', function() use ($app) {
        if (!require_admin()) {return;}
        render_json(all_users()->toArray());
    });
    $app->get('/skills/:netid', function($netid) use ($app) {
        render_json(skills_netid($netid));
    });
    $app->post('/skills/:netid', function($netid) use ($app) {
        // We need to be logged in, AND have it be the correct user (or an admin)
        if (!require_authenticated(false,$netid,true)) {return;}

        $data = $app->request->getBody();
        if (!$data) {
            $app->stop();
        }
        $data = json_decode($data,true);
        update_skills($netid, $data);
        render_json(array("success"=>true));
    });
    $app->get('/profile/:netid', function($netid) use ($app) {
        render_json(get_user_profile($netid)->toArray());
    });
    $app->post('/profile/:netid', function($netid) use ($app) {
        // Require either the user whose profile this is or an admin
        if (!require_authenticated(false, $netid, true)) { return; }
        $profileJSON = json_decode($app->request->getBody());
        $settingImage = false;
        if (array_key_exists("profileImage", $profileJSON)) {
            $profileImage = $profileJSON->profileImage;
            if (preg_match('/data:(image\/[a-zA-Z]+);base64,(.+)/', $profileImage, $matches)) {
                $profileImage = imagecreatefromstring(base64_decode($matches[2]));
                if (imagesx($profileImage) <= MAX_IMAGE_WIDTH && imagesy($profileImage) <= MAX_IMAGE_HEIGHT) {
                    $settingImage = true;
                    $path = realpath(dirname(__FILE__)) . '/images/' . $netid . '.png';
                    imagepng($profileImage, $path);
                }
            }
        }
        $profile = get_user_profile($netid);
        // Ensure the user can't be changed
        $user = $profile->getUser();
        $profile->fromJSON($app->request->getBody());
        if ($settingImage) {
            $user->setPicture('api/images/' . $netid . '.png');
            $user->save();
        }
        // Set the user back
        $profile->setUser($user);
        $profile->save();
        render_json($profile->toArray());

    });
    $app->get('/members', function() use ($app) {
        $members = memberProfileQuery::create()
            ->joinWith('User')
            ->where('Visible = 1')
            ->find();
        $members = process_member_resultset($members);
        render_json($members);
    });
});
$app->group('/helphours', function() use ($app) {
    $app->get('/all', function() use ($app) {
        if (!require_admin()) { return; }
        $helphours = helpHourQuery::create()
        ->joinWith('User')
        ->find();
        render_json($helphours->toArray());
    });
    $app->get('/get/:userid', function($userid) use ($app) {
        if ($app->request->params('unapproved')) {
            $helphours = helpHourQuery::create()->where('helpHour.UserId = ?', $userid)->find();
            render_json($helphours->toArray());
        }
        else {
            $helphours = helpHourQuery::create()->where('helpHour.UserId = ?', $userid)->where('helpHour.approved = true')->find();
            render_json($helphours->toArray());
        }
    });
    $app->get('/today', function() use($app) {
        $helpHours = helpHourQuery::create()
            ->where('helpHour.' . date('l') . ' = true')
            ->where('helpHour.approved = 1')
            ->joinWith('User')
            ->find();
        render_json(process_helphour_resultset($helpHours));
    });
    $app->get('/active', function() use ($app) {
        $helpHours = helpHourQuery::create()
            ->where('helpHour.approved = 1')
            ->joinWith('User')
            ->find();
        render_json(process_helphour_resultset($helpHours));
    });
    $app->get('/now', function() use($app) {
        $helpHours = helpHourQuery::create()
            ->where('helpHour.' . date('l'). ' = true')
            ->where('helpHour.StartTime <= ?', date("H:i"))
            ->where('helpHour.EndTime > ?', date("H:i"))
            ->where('helpHour.approved = 1')
            ->joinWith('User')
            ->find();
        render_json(process_helphour_resultset($helpHours));
    });
    $app->get('/unapproved', function() use($app) {
        if (!require_admin()) { return; }
        $helpHours = helpHourQuery::create()
            ->where('helpHour.approved = 0')
            ->joinWith('User')
            ->find();
        render_json(process_helphour_resultset($helpHours));
    });
    $app->get('/unapproved/count', function() use($app) {
        if (!require_admin()) { return; }
        render_json(helpHourQuery::create()->where('helpHour.Approved = 0')->count());
    });
    $app->get('/approve/:id', function($id) use ($app) {
        if (!require_admin()) { return; }
        $helpHour = helpHourQuery::create()->findPk($id);
        $helpHour->setApproved(1);
        $helpHour->save();
        render_json("Success");
    });
    $app->get('/unapprove/:id', function($id) use ($app) {
        if (!require_admin()) { return; }
        helpHourQuery::create()->findPk($id)->delete();
        render_json("Success");
    });
    $app->post('/submit', function() use($app) {
        if (!require_authenticated()) { return; }
        $data = json_decode($app->request->getBody());
        $who = get_loggedin_info();
        $data->UserId = $who['user']['Id'];
        $obj = new helpHour();
        $obj->fromJSON(json_encode($data));
        $obj->setStartTime(convert_timezone($obj->getStartTime()));
        $obj->setEndTime(convert_timezone($obj->getEndTime()));
        $obj->save();
        render_json($obj->toArray());
    });
    $app->get('/signin/:id', function($id) use ($app) {
        $helphour = helpHourQuery::create()->findPk($id);
        if (!require_authenticated(false, $helphour->getUser()->getNetid())) { return; }
        if (signin_helphour($helphour)) {
            render_json("Signed in correctly.");
        }
        else {
            render_json("Already signed in or other error.");
        }
    });
});
$app->get('/whoami', function() use ($app) {
    $who = get_loggedin_info();
    $who['profile'] = get_user_profile($who['username'])->toArray();
    render_json($who);
});
$app->post('/contact', function() use ($app) {
    $data = $app->request->getBody();
    if (!$data) {
        $app->stop();
    }
    $data = json_decode($data,true);
    $data['fromName']  = array_key_exists('fromName', $data) ? $data['fromName'] : "Anonymous";
    $data['fromEmail'] = array_key_exists('fromEmail', $data) ? $data['fromEmail'] : "anon@anon";
    send_contactform_email($data['fromName'], $data['fromEmail'], $data['message']);
});

$app->run();
