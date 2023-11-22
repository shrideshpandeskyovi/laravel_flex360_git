<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TrainingController extends Controller
{
    public function getCourseResult($email)
    {
        $progress = DB::table('tbltraining_resource_progress_trn')
            ->select('progress')
            ->where('email', $email)
            ->join('tbltraining_courses_ref', 'tbltraining_courses_ref.resource_id', '=', 'tbltraining_resource_progress_trn.content_id')
            ->get()
            ->toArray();

        $courseProgress = 0;
        foreach ($progress as $record) {
            $courseProgress += $record->progress;
        }

        $courses = DB::table('tbltraining_courses_ref')
            ->selectRaw('COUNT(row_id) as title_count')
            ->where('is_active', 0)
            ->first();

        $titleCount = $courses->title_count;

        $avgCourseProgress = ($courseProgress / $titleCount) * 100;

        return ['avg' => $avgCourseProgress, 'total_cnt' => $titleCount, 'progress_cnt' => $courseProgress];
    }

    public function updateTrainingResult()
    {
        try {
            $emails = DB::table('tbltraining_resource_progress_trn')
                ->distinct()
                ->select('email')
                ->get();

            if (!empty($emails)) {
                foreach ($emails as $email) {
                    $finalResult = $this->getCourseResult($email->email);
                    $avg = $finalResult['avg'];
                    $totalCount = $finalResult['total_cnt'];
                    $progressCount = floor($finalResult['progress_cnt']);
                    $finalString = $progressCount . '/' . $totalCount . ' (' . round($avg) . '%)';

                    DB::table(db_prefix() . 'staff')
                        ->where('email', $email->email)
                        ->update([
                            'training_result' => $finalString,
                            'training_avg' => round($avg),
                            'role' => round($avg) == 100 ? '2' : null,
                        ]);
                }
            }

            echo 'Data Updated';
        } catch (\Exception $ex) {
            echo '<pre>';
            print_r($ex);
            die;
        }
    }

    public function test()
    {
        return view('admin.training.manage');
    }

    public function import()
    {
        ini_set('memory_limit', -1);

        try {
            $this->validate(request(), [
                'file_xlsx' => 'required|mimes:xlsx',
            ]);

            $file = request()->file('file_xlsx');
            $filename = uniqid() . '_' . $file->getClientOriginalName();
            $tempUrl = TEMP_FOLDER . $filename;

            $file->move(TEMP_FOLDER, $filename);

            $spreadsheet = IOFactory::load($tempUrl);
            $originalData = array_slice($spreadsheet->getActiveSheet()->toArray(), 7);

            $emailIndex = 0;
            $progressIndex = 5;

            if ($originalData[0][$emailIndex] == 'Email' && $originalData[0][$progressIndex] == 'Progress') {
                array_shift($originalData);

                DB::table(db_prefix() . 'training_resource_progress_trn')->truncate();

                foreach ($originalData as $user) {
                    DB::table(db_prefix() . 'training_resource_progress_trn')->insert([
                        'email' => $user[0],
                        'first_name' => $user[1],
                        'last_name' => $user[2],
                        'content_first_accessed_on' => $user[3],
                        'content_id' => $user[4],
                        'progress' => $user[5],
                        'first_completed_on' => $user[6],
                        'content_type' => $user[7],
                        'content_name' => $user[8],
                        'content_complexity_level' => $user[9],
                        'content_source_name' => $user[10],
                        'delivery_mode' => $user[11],
                        'content_creators' => $user[12],
                        'resource_type' => $user[13],
                        'content_duration_in_seconds' => $user[14],
                        'content_description' => $user[15],
                        'content_keywords' => $user[16],
                        'content_mime_type' => $user[17],
                        'content_status' => $user[18],
                        'language' => $user[19],
                        'content_last_accessed_on' => $user[20],
                        'date' => $user[21],
                        'root_org' => $user[22],
                        'org' => $user[23],
                        'is_user_active' => $user[24],
                    ]);

                    DB::table(db_prefix() . 'training_progress_data')->insert([
                        'email' => $user[0],
                        'first_name' => $user[1],
                        'last_name' => $user[2],
                        'content_first_accessed_on' => $user[3],
                        'content_id' => $user[4],
                        'progress' => $user[5],
                        'first_completed_on' => $user[6],
                        'content_type' => $user[7],
                        'content_name' => $user[8],
                        'content_complexity_level' => $user[9],
                        'content_source_name' => $user[10],
                        'delivery_mode' => $user[11],
                        'content_creators' => $user[12],
                        'resource_type' => $user[13],
                        'content_duration_in_seconds' => $user[14],
                        'content_description' => $user[15],
                        'content_keywords' => $user[16],
                        'content_mime_type' => $user[17],
                        'content_status' => $user[18],
                        'language' => $user[19],
                        'content_last_accessed_on' => $user[20],
                        'date' => $user[21],
                        'root_org' => $user[22],
                        'org' => $user[23],
                        'is_user_active' => $user[24],
                    ]);
                }

                $this->updateTrainingResult();

                set_alert('success', _l('imported_file'));
            } else {
                set_alert('danger', _l('file_contains_invalid_format_data'));
            }

            return view('admin.training.upload');
        } catch (\Exception $e) {
            die('Error loading file "' . pathinfo($tempUrl, PATHINFO_BASENAME) . '": ' . $e->getMessage());
        }
    }
}
