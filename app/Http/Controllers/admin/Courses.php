<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Libraries\Import\ImportCourses;
use Illuminate\Http\Request;

class CoursesController extends Controller
{
    public function test()
    {
        return view('admin.courses.manage');
    }

    public function import(Request $request)
    {
        $import = app()->make(ImportCourses::class);
        $import->setDatabaseFields(\DB::getSchemaBuilder()->getColumnListing('training_courses_ref'));

        if ($request->input('download_sample') === 'true') {
            $import->downloadSample();
        }

        if ($request->post() && $request->hasFile('file_csv')) {
            $import->setSimulation($request->post('simulate'))
                ->setTemporaryFileLocation($request->file('file_csv')->getPathname())
                ->setFilename($request->file('file_csv')->getClientOriginalName())
                ->perform();

            $data['total_rows_post'] = $import->totalRows();

            if (!$import->isSimulation()) {
                set_alert('success', _l('import_total_imported', $import->totalImported()));
            }
        }

        $data['title'] = _l('import');
        return view('admin.courses.manage');
    }
}
