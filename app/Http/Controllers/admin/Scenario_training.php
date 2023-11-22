<?php

namespace App\Http\Controllers;

class ScenarioTrainingController extends AdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get()
    {
        $courseid = request()->input('courseid');
        $data = [];
        $data['title'] = 'Download Application';
        $training_id = strtolower(auth()->user()->TrainingID);
        $data['courseid'] = $courseid;
        $data['training_id'] = $training_id;
        return view('admin.training.scenario_get', $data);
    }

    public function get1()
    {
        $courseid = request()->input('courseid');
        $training_id = strtolower(auth()->user()->TrainingID);

        if (!empty($training_id) && !empty($courseid)) {
            $url = config('flexez_training_url') . '?courseid=' . $courseid . '&traineeid=' . $training_id;
            ?>
            <script>
                setTimeout(function () {
                    open_flexez_trainig_popup();
                }, 500);

                function open_flexez_trainig_popup() {
                    let makeWindow = window.open('<?php echo $url; ?>', '_top', "popupBlocker=false");
                    if (makeWindow) {
                        makeWindow.resizeTo(screen.width, screen.height);
                        window.close();
                    }
                }
            </script>
            <?php
        }
    }

    public function iframe()
    {
        $courseid = request()->input('courseid');
        $training_id = strtolower(auth()->user()->TrainingID);
        $url = '';

        if (!empty($training_id) && !empty($courseid)) {
            $url = config('flexez_training_url') . '?courseid=' . $courseid . '&traineeid=' . $training_id;
        }

        $data['url'] = $url;
        return view('admin.candidates.scenario_training', $data);
    }

    public function popup()
    {
        $courseid = request()->input('courseid');
        $training_id = strtolower(auth()->user()->TrainingID);

        if (!empty($training_id) && !empty($courseid)) {
            $url = config('flexez_training_url') . '?courseid=' . $courseid . '&traineeid=' . $training_id;
        }
        ?>
        <script>
            const screenWidth = window.screen.width;
            const screenHeight = window.screen.height;
            const features = 'toolbar=no,location=no,directories=no,status=no,menubar=no,scrollbars=no,resizable=no,width=window.screen.width,height=window.screen.height,top=0,left=0';
            let makeWindow = window.open("<?php echo $url; ?>", '', features);
            window.close();
        </script>
        <?php
    }

    public function ac()
    {
        $courseid = request()->input('courseid');
        $training_id = strtolower(auth()->user()->TrainingID);

        if (!empty($training_id) && !empty($courseid)) {
            $url = config('flexez_training_url') . '?courseid=' . $courseid . '&traineeid=' . $training_id;
        }
        ?>
        <body onload="open_flexez_trainig_popup()"></body>
        <script>
            function open_flexez_trainig_popup() {
                let makeWindow1 = window.open('<?php echo $url; ?>', '_top', "popupBlocker=false");
                if (makeWindow1) {
                    makeWindow1.resizeTo(screen.width, screen.height);
                }
            }
        </script>
        <?php
    }
}
