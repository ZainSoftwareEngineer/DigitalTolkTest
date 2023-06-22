<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;
use DTApi\Repository\BaseRepository;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get Users Jobs
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cUser = User::find($user_id);
        $userType = '';
        $emergencyJobs = [];
        $normalJobs = [];
        $jobs = null;
        if ($cUser && $cUser->is('customer')) {
            $jobs = $cUser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
            $userType = 'customer';
        } elseif ($cUser && $cUser->is('translator')) {
            $jobs = Job::getTranslatorJobs($cUser->id, 'new')
                ->pluck('jobs')
                ->all();
            $userType = 'translator';
        }
        if ($jobs) {
            foreach ($jobs as $jobItem) {
                if ($jobItem->immediate == 'yes') {
                    $emergencyJobs[] = $jobItem;
                } else {
                    $normalJobs[] = $jobItem;
                }
            }
            $normalJobs = collect($normalJobs)
                ->each(function ($item, $key) use ($user_id) {
                    $item['usercheck'] = Job::checkParticularJob($user_id, $item);
                })->sortBy('due')->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cUser' => $cUser,
            'userType' => $userType
        ];
    }

    /**
     * Get User Job History
     * @param $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page');
        $pageNum = isset($page) ? $page : "1";
        $cUser = User::find($user_id);
        $userType = '';
        $emergencyJobs = [];
        $normalJobs = [];
        $jobs = [];
        $numPages = 0;
        if ($cUser && $cUser->is('customer')) {
            $pageNum = 0;
            $jobs = $cUser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);
            $userType = 'customer';
        } elseif ($cUser && $cUser->is('translator')) {
            $jobs_ids = Job::getTranslatorJobsHistoric($cUser->id, 'historic', $pageNum);
            $totalJobs = $jobs_ids->total();
            $numPages = ceil($totalJobs / 15);
            $userType = 'translator';
            $jobs = $jobs_ids;
            $normalJobs = $jobs_ids;
        }
        return [
            'emergencyJobs' => $emergencyJobs,
            'noramlJobs' => $normalJobs,
            'jobs' => $jobs,
            'cuser' => $cUser,
            'usertype' => $userType,
            'numpages' => $numPages,
            'pagenum' => $pageNum
        ];
    }

    public function responseMaker($fieldName){
        $response = null;
        $response['status'] = 'fail';
        $response['message'] = "Du måste fylla in alla fält";
        $response['field_name'] = $fieldName;
        return $response;
    }

    /**
     * Store Job Data
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediateTime = 5;
        $consumer_type = $user->userMeta->consumer_type;
        if ($user->user_type == config('constants.CUSTOMER_ROLE_ID')) {
            $cUser = $user;
            if (!isset($data['from_language_id'])) {
                return $this->responseMaker('from_language_id');
            }
            if ($data['immediate'] == 'no') {
                if (empty($data['due_date'])) {
                    return $this->responseMaker('due_date');
                }
                if (empty($data['due_time'])) {
                    return $this->responseMaker('due_time');
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    return $this->responseMaker('customer_phone_type');
                }
                if (empty($data['duration'])) {
                    return $this->responseMaker('duration');
                }
            } else {
                if (empty($data['duration'])) {
                    return $this->responseMaker('duration');
                }
            }
            $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
            $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
            $response['customer_physical_type'] = $data['customer_physical_type'];

            if ($data['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinute($immediateTime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';
            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in past";
                    return $response;
                }
            }
            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } else if (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }

            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            } else if (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } else if (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } else if (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }

            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            } else if (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'n_law';
            } else if (in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'n_health';
            }

            switch ($consumer_type) {
                case 'rwsconsumer':
                    $data['job_type'] = 'rws';
                    break;
                case 'ngo':
                    $data['job_type'] = 'unpaid';
                    break;
                case 'paid':
                    $data['job_type'] = 'paid';
                    break;
            }

            $data['b_created_at'] = date('Y-m-d H:i:s');

            if (isset($due)) {
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            }

            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';


            $job = $cUser->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
            $data['job_for'] = $this->prepareJobForData($job);
            $data['customer_town'] = $cUser->userMeta->city;
            $data['customer_type'] = $cUser->userMeta->customer_type;
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }
        return $response;
    }

    public function prepareJobForData($job){
        $jobFor = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $jobFor[] = 'Man';
            } else if ($job->gender == 'female') {
                $jobFor[] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $jobFor[] = 'normal';
                $jobFor[] = 'certified';
            } else if ($job->certified == 'yes') {
                $jobFor[] = 'certified';
            } else {
                $jobFor[] = $job->certified;
            }
        }
        return $jobFor;
    }

    /**
     * Store Job Email
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail($data['user_email_job_id']);
        $job->user_email = isset($data['user_email']) ? $data['user_email'] : '';
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();

        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;
    }

    /**
     * Save job's information to data for sending Push
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {

        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $due = explode(" ", $job->due);
        $dueDate = $due[0];
        $dueTime = $due[1];

        $data['due_date'] = $dueDate;
        $data['due_time'] = $dueTime;

        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;

    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translatorType = $user_meta->translator_type;

        switch ($translatorType) {
            case 'professional':
                $jobType = 'paid'; /*show all jobs for professionals.*/
                break;
            case 'rwstranslator':
                $jobType = 'rws'; /* for rwstranslator only show rws jobs. */
                break;
            case 'volunteer':
                $jobType = 'unpaid';/* for volunteers only show unpaid jobs. */
                break;
        }

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userLanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translatorLevel = $user_meta->translator_level;
        $jobIds = Job::getJobs($user_id, $jobType, 'pending', $userLanguage, $gender, $translatorLevel);
        foreach ($jobIds as $k => $v)     // checking translator town
        {
            $job = Job::find($v->id);
            $jobUserId = $job->user_id;
            $checkTown = Job::checkTowns($jobUserId, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checkTown == false) {
                unset($jobIds[$k]);
            }
        }
        return TeHelper::convertJobIdsInObjs($jobIds);
    }

    /**
     * Send Notification To Translator
     * @param $job
     * @param array $data
     * @param $excludeUserId
     */
    public function sendNotificationTranslator($job, $excludeUserId, $data = [])
    {
        $users = User::all();
        $translatorArray = array();            // suitable translators (no need to delay push)
        $delpayTranslatorArray = array();     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $excludeUserId) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) continue;
                $notGetEmergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $notGetEmergency == 'yes') continue;
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $jobForTranslator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($jobForTranslator == 'SpecificJob') {
                            $jobChecker = Job::checkParticularJob($userId, $oneJob);
                            if (($jobChecker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpayTranslatorArray[] = $oneUser;
                                } else {
                                    $translatorArray[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        if ($data['immediate'] == 'no') {
            $msgContents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msgContents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msgText = array(
            "en" => $msgContents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delpayTranslatorArray, $msgText, $data]);
        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpayTranslatorArray, $job->id, $data, $msgText, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and returns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // analyse weather it's phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $userId
     * @return bool
     */
    public function isNeedToDelayPush($userId)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $notGetNightTime = TeHelper::getUsermeta($userId, 'not_get_nighttime');
        return $notGetNightTime == 'yes' ? true : false;
    }

    /**
     * Function to check if need to send the push
     * @param $userId
     * @return bool
     */
    public function isNeedToSendPush($userId)
    {
        $notGetNotification = TeHelper::getUsermeta($userId, 'not_get_notification');
        return $notGetNotification == 'yes' ? false : true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $jobId
     * @param $data
     * @param $msgText
     * @param $isNeedDelay
     */
    public function sendPushNotificationToSpecificUsers($users, $jobId, $data, $msgText, $isNeedDelay)
    {

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $jobId, [$users, $data, $msgText, $isNeedDelay]);
        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

        $userTags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $jobId;
        $iosSound = 'default';
        $androidSound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $androidSound = 'normal_booking';
                $iosSound = 'normal_booking.mp3';
            } else {
                $androidSound = 'emergency_booking';
                $iosSound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($userTags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msgText,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $androidSound,
            'ios_sound'      => $iosSound
        );
        if ($isNeedDelay) {
            $nextBusinessTime = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $nextBusinessTime;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $jobId . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * Get Potential Translators
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        $jobType = $job->job_type;
        $translatorType = '';
        switch ($jobType) {
            case 'paid':
                $translatorType = 'professional';
                break;
            case 'rws':
                $translatorType = 'rwstranslator';
                break;
            case 'unpaid':
                $translatorType = 'volunteer';
                break;
        }

        $jobLanguage = $job->from_language_id;
        $gender = $job->gender;
        $translatorLevel = [];
        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translatorLevel[] = 'Certified';
                $translatorLevel[] = 'Certified with specialisation in law';
                $translatorLevel[] = 'Certified with specialisation in health care';
            }
            elseif($job->certified == 'law' || $job->certified == 'n_law')
            {
                $translatorLevel[] = 'Certified with specialisation in law';
            }
            elseif($job->certified == 'health' || $job->certified == 'n_health')
            {
                $translatorLevel[] = 'Certified with specialisation in health care';
            }
            else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translatorLevel[] = 'Layman';
                $translatorLevel[] = 'Read Translation courses';
            }
            elseif ($job->certified == null) {
                $translatorLevel[] = 'Certified';
                $translatorLevel[] = 'Certified with specialisation in law';
                $translatorLevel[] = 'Certified with specialisation in health care';
                $translatorLevel[] = 'Layman';
                $translatorLevel[] = 'Read Translation courses';
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        return User::getPotentialUsers($translatorType, $jobLanguage, $gender, $translatorLevel, $translatorsId);
    }

    /**
     * @param $id
     * @param $data
     * @param $cUser
     * @return mixed
     */
    public function updateJob($id, $data, $cUser)
    {
        $job = Job::find($id);
        $oldTime = null;
        $oldLang = null;
        $currentTranslator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($currentTranslator))
            $currentTranslator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $logData = [];
        $langChanged = false;
        $changeTranslator = $this->changeTranslator($currentTranslator, $data, $job);
        if ($changeTranslator['translatorChanged']) $logData[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $oldTime = $job->due;
            $job->due = $data['due'];
            $logData[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $oldLang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $logData[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cUser->id . '(' . $cUser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $logData);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $oldTime);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $oldLang);
        }
        return ['Updated'];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $oldStatus = $job->status;
        $statusChanged = false;
        if ($oldStatus != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $logData = [
                    'old_status' => $oldStatus,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $logData];
            }
        }
        return ['statusChanged' => $statusChanged];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
            $this->sendNotificationTranslator($job, '*', $job_data);   // send Push all suitable translators
            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $sessionTime = $diff[0] . ' tim ' . $diff[1] . ' min';
            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $sessionTime,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $sessionTime,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
        }
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msgText = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msgText = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $usersArray = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($usersArray, $job->id, $data, $msgText, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $currentTranslator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($currentTranslator, $data, $job)
    {
        $translatorChanged = false;
        $newTranslator = [];
        if (!is_null($currentTranslator) || !empty($data['translator']) || !empty($data['translator_email'])) {
            $logData = [];
            if (!is_null($currentTranslator) && (($currentTranslator->user_id != $data['translator']) || !empty($data['translator_email']))) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $newTranslator = $currentTranslator->toArray();
                $newTranslator['user_id'] = $data['translator'];
                unset($newTranslator['id']);
                $newTranslator = Translator::create($newTranslator);
                $currentTranslator->cancel_at = Carbon::now();
                $currentTranslator->save();
                $logData[] = [
                    'old_translator' => $currentTranslator->user->email,
                    'new_translator' => $newTranslator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($currentTranslator) && isset($data['translator']) && (!empty($data['translator']) || !empty($data['translator_email']))) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $newTranslator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $logData[] = [
                    'old_translator' => null,
                    'new_translator' => $newTranslator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $newTranslator, 'log_data' => $logData];
        }
        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $oldDue
     * @param $newDue
     * @return array
     */
    private function changeDue($oldDue, $newDue)
    {
        $dateChanged = false;
        if ($oldDue != $newDue) {
            $logData = [
                'old_due' => $oldDue,
                'new_due' => $newDue
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $logData];
        }

        return ['dateChanged' => $dateChanged];

    }

    /**
     * @param $job
     * @param $currentTranslator
     * @param $newTranslator
     */
    public function sendChangedTranslatorNotification($job, $currentTranslator, $newTranslator)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($currentTranslator) {
            $user = $currentTranslator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $newTranslator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);

    }

    /**
     * @param $job
     * @param $oldTime
     */
    public function sendChangedDateNotification($job, $oldTime)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $oldTime
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $oldTime
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $oldLang
     */
    public function sendChangedLangNotification($job, $oldLang)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $oldLang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msgText = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $usersArray = array($user);
            $this->sendPushNotificationToSpecificUsers($usersArray, $job->id, $data, $msgText, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $jobId
     */
    public function sendNotificationByAdminCancelJob($jobId)
    {
        $job = Job::findOrFail($jobId);
        $user_meta = $job->user->userMeta()->first();
        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due = explode(" ", $job->due);
        $dueDate = $due[0];
        $dueTime = $due[1];
        $data['due_date'] = $dueDate;
        $data['due_time'] = $dueTime;
        $data['job_for'] = $this->prepareJobForData($job);

        $this->sendNotificationTranslator($job, '*', $data);   // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msgText = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msgText = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $usersArray = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($usersArray, $job->id, $data, $msgText, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $userTags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $userTags .= ',{"operator": "OR"},';
            }
            $userTags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $userTags .= ']';
        return $userTags;
    }

    /**
     * @param $data
     * @param $user
     * @return array
     */
    public function acceptJob($data, $user)
    {

        $cUser = $user;
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        if (!Job::isTranslatorAlreadyBooked($jobId, $cUser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cUser->id, $jobId)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->first();
                $mailer = new AppMailer();

                $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning #' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job' => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                // Flash message
                $flashMessage = 'Bokningen har accepterats.';
                session()->flash('success', $flashMessage);

                $jobs = $this->getPotentialJobs($cUser);
                $response = [
                    'list' => json_encode(['jobs' => $jobs, 'job' => $job], true),
                    'status' => 'success'
                ];
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;

    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($jobId, $cUser)
    {

        $job = Job::findOrFail($jobId);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($jobId, $cUser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cUser->id, $jobId)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning #' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job' => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                // Flash message
                $flashMessage = 'Bokningen har accepterats.';
                session()->flash('success', $flashMessage);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msgText = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $usersArray = array($user);
                    $this->sendPushNotificationToSpecificUsers($usersArray, $jobId, $data, $msgText, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = array();
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $cUser = $user;
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($cUser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msgText = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $usersArray = array($translator);
                    $this->sendPushNotificationToSpecificUsers($usersArray, $jobId, $data, $msgText, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msgText = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($this->isNeedToSendPush($customer->id)) {
                        $usersArray = array($customer);
                        $this->sendPushNotificationToSpecificUsers($usersArray, $jobId, $data, $msgText, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
                Job::deleteTranslatorJobRel($translator->id, $jobId);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $translator->id, $data);   // send Push all sutiable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cUser)
    {
        $cUser_meta = $cUser->userMeta;
        $jobType = 'unpaid';
        $translatorType = $cUser_meta->translator_type;
        switch ($translatorType) {
            case 'professional':
                $jobType = 'paid';
                break;
            case 'rwstranslator':
                $jobType = 'rws';
                break;
            case 'volunteer':
                $jobType = 'unpaid';
                break;
        }

        $languages = UserLanguages::where('user_id', '=', $cUser->id)->get();
        $userLanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cUser_meta->gender;
        $translatorLevel = $cUser_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $jobIds = Job::getJobs($cUser->id, $jobType, 'pending', $userLanguage, $gender, $translatorLevel);
        foreach ($jobIds as $k => $job) {
            $jobUserId = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cUser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cUser->id, $job);
            $checkTown = Job::checkTowns($jobUserId, $cUser->id);

            if($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                unset($jobIds[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checkTown == false) {
                unset($jobIds[$k]);
            }
        }
        return $jobIds;
    }

    public function endJob($post_data)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $post_data["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        if($jobDetail->status != 'started')
            return ['status' => 'success'];

        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $sessionExplode = explode(':', $job->session_time);
        $sessionTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completedDate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }


    public function customerNotCall($post_data)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $post_data["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completedDate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $cUser = auth()->user();
        $consumer_type = $cUser->consumer_type;

        if ($cUser && $cUser->user_type == config('constants.SUPERADMIN_ROLE_ID')) {
            $allJobs = Job::query();

            if (!empty($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0')
                    ->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', '3');
                    });

                if (!empty($requestData['count']) && $requestData['count'] != 'false') {
                    return ['count' => $allJobs->count()];
                }
            }


            if (!empty($requestData['id'])) {
                $id = $requestData['id'];
                if (is_array($id)) {
                    $allJobs->whereIn('id', $id);
                } else {
                    $allJobs->where('id', $id);
                }
                $requestData = array_only($requestData, ['id']);
            }

            if (!empty($requestData['lang'])) {
                $lang = $requestData['lang'];
                $allJobs->whereIn('from_language_id', $lang);
            }

            if (!empty($requestData['status'])) {
                $status = $requestData['status'];
                $allJobs->whereIn('status', $status);
            }

            if (!empty($requestData['expired_at'])) {
                $expiredAt = $requestData['expired_at'];
                $allJobs->where('expired_at', '>=', $expiredAt);
            }

            if (!empty($requestData['will_expire_at'])) {
                $willExpireAt = $requestData['will_expire_at'];
                $allJobs->where('will_expire_at', '>=', $willExpireAt);
            }


            if (!empty($requestData['customer_email'])) {
                $customerEmails = $requestData['customer_email'];
                $users = User::whereIn('email', $customerEmails)->get();
                if ($users) {
                    $userIds = collect($users)->pluck('id')->all();
                    $allJobs->whereIn('user_id', $userIds);
                }
            }

            if (!empty($requestData['translator_email'])) {
                $translatorEmails = $requestData['translator_email'];
                $users = User::whereIn('email', $translatorEmails)->get();
                if ($users) {
                    $userIds = collect($users)->pluck('id')->all();
                    $allJobIDs = DB::table('translator_job_rel')
                        ->whereNull('cancel_at')
                        ->whereIn('user_id', $userIds)
                        ->pluck('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }


            if (!empty($requestData['filter_timetype'])) {
                if ($requestData['filter_timetype'] == "created") {
                    if (!empty($requestData['from'])) {
                        $allJobs->where('created_at', '>=', $requestData['from']);
                    }
                    if (!empty($requestData['to'])) {
                        $to = $requestData['to'] . " 23:59:00";
                        $allJobs->where('created_at', '<=', $to);
                    }
                    $allJobs->orderBy('created_at', 'desc');
                }
                elseif ($requestData['filter_timetype'] == "due") {
                    if (!empty($requestData['from'])) {
                        $allJobs->where('due', '>=', $requestData['from']);
                    }
                    if (!empty($requestData['to'])) {
                        $to = $requestData['to'] . " 23:59:00";
                        $allJobs->where('due', '<=', $to);
                    }
                    $allJobs->orderBy('due', 'desc');
                }
            }


            if (!empty($requestData['job_type'])) {
                $allJobs->whereIn('job_type', $requestData['job_type']);
            }

            if (isset($requestData['physical'])) {
                $allJobs->where('customer_physical_type', $requestData['physical'])->where('ignore_physical', 0);
            }

            if (isset($requestData['phone'])) {
                $allJobs->where('customer_phone_type', $requestData['phone']);
                if (isset($requestData['physical'])) {
                    $allJobs->where('ignore_physical_phone', 0);
                }
            }

            if (isset($requestData['flagged'])) {
                $allJobs->where('flagged', $requestData['flagged'])->where('ignore_flagged', 0);
            }

            if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if (isset($requestData['salary']) && $requestData['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }


            if (isset($requestData['count']) && $requestData['count'] == 'true') {
                $count = $allJobs->count();
                return ['count' => $count];
            }

            if (isset($requestData['consumer_type']) && $requestData['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function ($q) use ($requestData) {
                    $q->where('consumer_type', $requestData['consumer_type']);
                });
            }

            if (isset($requestData['booking_type'])) {
                if ($requestData['booking_type'] == 'physical') {
                    $allJobs->where('customer_physical_type', 'yes');
                }
                if ($requestData['booking_type'] == 'phone') {
                    $allJobs->where('customer_phone_type', 'yes');
                }
            }


            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            $allJobs = ($limit == 'all') ? $allJobs->get() : $allJobs->paginate(15);

        } else {

            $allJobs = Job::query();

            if (!empty($requestData['id'])) {
                $allJobs->where('id', $requestData['id']);
                $requestData = array_only($requestData, ['id']);
            }

            $jobType = ($consumer_type == 'RWS') ? 'rws' : 'unpaid';
            $allJobs->where('job_type', '=', $jobType);

            if (!empty($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0')
                    ->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', '3');
                    });

                if (!empty($requestData['count']) && $requestData['count'] != 'false') {
                    return ['count' => $allJobs->count()];
                }
            }

            if (!empty($requestData['lang'])) {
                $allJobs->whereIn('from_language_id', $requestData['lang']);
            }
            if (!empty($requestData['status'])) {
                $allJobs->whereIn('status', $requestData['status']);
            }
            if (!empty($requestData['job_type'])) {
                $allJobs->whereIn('job_type', $requestData['job_type']);
            }
            if (!empty($requestData['customer_email'])) {
                $user = User::where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', $user->id);
                }
            }

            if (!empty($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (!empty($requestData['from'])) {
                    $allJobs->where('created_at', '>=', $requestData["from"]);
                }
                if (!empty($requestData['to'])) {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (!empty($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (!empty($requestData['from'])) {
                    $allJobs->where('due', '>=', $requestData["from"]);
                }
                if (!empty($requestData['to'])) {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            $allJobs = ($limit == 'all') ? $allJobs->get() : $allJobs->paginate(15);

        }
        return $allJobs;
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cUser = Auth::user();


        if ($cUser && $cUser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $jobId)->where('ignore', 0);
            if (!empty($requestData['lang'])) {
                $allJobs->whereIn('from_language_id', $requestData['lang']);
            }

            if (!empty($requestData['status'])) {
                $allJobs->whereIn('status', $requestData['status']);
            }

            if (!empty($requestData['customer_email'])) {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', $user->id);
                }
            }

            if (!empty($requestData['translator_email'])) {
                $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id')->toArray();
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }

            if (!empty($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (!empty($requestData['from'])) {
                    $allJobs->where('created_at', '>=', $requestData["from"]);
                }
                if (!empty($requestData['to'])) {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }

            if (!empty($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (!empty($requestData['from'])) {
                    $allJobs->where('due', '>=', $requestData["from"]);
                }
                if (!empty($requestData['to'])) {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            if (!empty($requestData['job_type'])) {
                $allJobs->whereIn('job_type', $requestData['job_type']);
            }

            $allJobs->select('jobs.*', 'languages.language')
                ->whereIn('id', $jobId)
                ->orderBy('created_at', 'desc')
                ->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestData];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cUser = Auth::user();

        if ($cUser && ($cUser->is('superadmin') || $cUser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('ignore_expired', 0)
                ->where('status', 'pending')
                ->where('due', '>=', Carbon::now());

            if (!empty($requestData['lang'])) {
                $allJobs->whereIn('from_language_id', $requestData['lang']);
            }

            if (!empty($requestData['status'])) {
                $allJobs->whereIn('status', $requestData['status']);
            }

            if (!empty($requestData['customer_email'])) {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', $user->id);
                }
            }

            if (!empty($requestData['translator_email'])) {
                $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id')->toArray();
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }

            if (!empty($requestData['filter_timetype'])) {
                if ($requestData['filter_timetype'] == "created") {
                    if (!empty($requestData['from'])) {
                        $allJobs->where('created_at', '>=', $requestData["from"]);
                    }
                    if (!empty($requestData['to'])) {
                        $to = $requestData["to"] . " 23:59:00";
                        $allJobs->where('created_at', '<=', $to);
                    }
                    $allJobs->orderBy('created_at', 'desc');
                } elseif ($requestData['filter_timetype'] == "due") {
                    if (!empty($requestData['from'])) {
                        $allJobs->where('due', '>=', $requestData["from"]);
                    }
                    if (!empty($requestData['to'])) {
                        $to = $requestData["to"] . " 23:59:00";
                        $allJobs->where('due', '<=', $to);
                    }
                    $allJobs->orderBy('due', 'desc');
                }
            }

            if (!empty($requestData['job_type'])) {
                $allJobs->whereIn('job_type', $requestData['job_type']);
            }

            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('created_at', 'desc')
                ->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestData];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobId = $request['jobid'];
        $userId = $request['userid'];

        $job = Job::find($jobId);
        $job = $job->toArray();

        $data = array();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userId;
        $data['job_id'] = $jobId;
        $data['cancel_at'] = Carbon::now();

        $dataReOpen = array();
        $dataReOpen['status'] = 'pending';
        $dataReOpen['created_at'] = Carbon::now();
        $dataReOpen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $dataReOpen['created_at']);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobId)->update($dataReOpen);
            $new_jobid = $jobId;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        Translator::where('job_id', $jobId)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

}