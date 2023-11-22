<?php

namespace App\Http\Controllers;

use App\Models\CandidateResult;
use App\Models\Yardstik;
use Illuminate\Http\Request;

class CandidateResultController extends Controller
{
    protected $candidateResultModel;
    protected $yardstikModel;

    public function __construct(CandidateResult $candidateResultModel, Yardstik $yardstikModel)
    {
        $this->candidateResultModel = $candidateResultModel;
        $this->yardstikModel = $yardstikModel;
    }

    /* List all candidates */
    public function index()
    {
        $data['candidate_result'] = $this->candidateResultModel->get_candidates();
        $data['title'] = __('FLEX360');

        return view('admin.candidate_result.manage', $data);
    }

    public function updateStatus(Request $request)
    {
        try {
            $id = $request->input('id');
            $status = $request->input('status');

            $result = $this->candidateResultModel->updateStatus($id, $status);

            if ($result) {
                return response()->json(['message' => 'Assessment status updated successfully.']);
            } else {
                return response()->json(['message' => 'Failed to update assessment status.'], 400);
            }

        } catch (\Exception $ex) {
            return response()->json(['message' => $ex->getMessage()], 500);
        }
    }

    public function __construct(CandidateResult $candidateResultModel)
    {
        $this->candidateResultModel = $candidateResultModel;
    }

    public function updateBackgroundCheckStatus(Request $request)
    {
        try {
            $id = $request->input('id');
            $status = $request->input('status');
            $source = $request->input('source');

            $result = $this->candidateResultModel->updateBackgroundCheckStatus($id, $status, $source);

            if ($result) {
                return response()->json(['message' => 'Background check status updated successfully.']);
            } else {
                return response()->json(['message' => 'Failed to update background check status.'], 400);
            }

        } catch (\Exception $ex) {
            return response()->json(['message' => $ex->getMessage()], 500);
        }
    }

    public function updateSystemAccessStatus(Request $request)
    {
        try {
            $id = $request->input('id');
            $status = $request->input('status');

            $result = $this->candidateResultModel->updateSystemAccessStatus($id, $status);

            if ($result) {
                return response()->json(['message' => 'System access status updated successfully.']);
            } else {
                return response()->json(['message' => 'Failed to update system access status.'], 400);
            }

        } catch (\Exception $ex) {
            return response()->json(['message' => $ex->getMessage()], 500);
        }
    }
}
