<?php

namespace DTApi\Http\Controllers;

use App\Http\Requests\IndexPageRequest;
use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * Index Page
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $authUser = auth()->user();
        $response = null;
        $isAdmin = ($authUser->user_type == config('constants.ADMIN_ROLE_ID') || $authUser->user_type == config('constants.SUPERADMIN_ROLE_ID'));
        if ($request->has('user_id')) {
            $userId = $request->get('user_id');
            $response = $this->repository->getUsersJobs($userId);
        } else if ($isAdmin) {
            $response = $this->repository->getAll($request);
        }
        return response($response);
    }

    /**
     * Display Jobs
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);
        return response($job);
    }

    /**
     * Store Jobs
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->store(auth()->user, $data);
        return response($response);
    }

    /**
     * Update Job
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->all();
        $cUser = auth()->user();
        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cUser);

        return response($response);
    }

    /**
     * Send Email with job Creation
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->storeJobEmail($data);
        return response($response);
    }

    /**
     * Get User job History
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        if($request->has('user_id')) {
            $userId = $request->get('user_id');
            $response = $this->repository->getUsersJobsHistory($userId, $request);
            return response($response);
        }
        return null;
    }

    /**
     * Accept job Process
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = auth()->user();
        $response = $this->repository->acceptJob($data, $user);
        return response($response);
    }

    /**
     * Accept job Process using Job Id
     * @param Request $request
     * @return mixed
     */
    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = auth()->user();
        $response = $this->repository->acceptJobWithId($data, $user);
        return response($response);
    }

    /**
     * Cancel Job
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = auth()->user();
        $response = $this->repository->cancelJobAjax($data, $user);
        return response($response);
    }

    /**
     * End Job
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->endJob($data);
        return response($response);
    }

    /**
     * Job Status when Customer didn't call
     * @param Request $request
     * @return mixed
     */
    public function customerNotCall(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->customerNotCall($data);
        return response($response);
    }

    /**
     * Get Potential Jobs
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $user = auth()->user();
        $response = $this->repository->getPotentialJobs($user);
        return response($response);
    }

    /**
     * Distance Feed
     * @param Request $request
     * @return mixed
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->all();

        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $jobId = $data['jobid'] ?? '';
        $session = $data['session_time'] ?? '';
        $flagged = $data['flagged'] == 'true' ? ($data['admincomment'] !== '' ? 'yes' : 'Please, add comment') : 'no';
        $manuallyHandled = $data['manually_handled'] == 'true' ? 'yes' : 'no';
        $byAdmin = $data['by_admin'] == 'true' ? 'yes' : 'no';
        $adminComment = $data['admincomment'] ?? '';
        if ($time || $distance) {
            Distance::where('job_id', '=', $jobId)->update([
                'distance' => $distance,
                'time' => $time
            ]);
        }
        if ($adminComment || $session || $flagged || $manuallyHandled || $byAdmin) {
            Job::where('id', '=', $jobId)->update([
                'admin_comments' => $adminComment,
                'flagged' => $flagged,
                'session_time' => $session,
                'manually_handled' => $manuallyHandled,
                'by_admin' => $byAdmin
            ]);
        }

        return response('Record updated!');
    }

    /**
     * Reopen Job
     * @param Request $request
     * @return mixed
     */
    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->reopen($data);
        return response($response);
    }

    /**
     * Resend Notification
     * @param Request $request
     * @return mixed
     */
    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $jobData = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, '*', $jobData);

        return response(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

}
