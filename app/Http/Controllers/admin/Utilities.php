<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use App\Models\UtilitiesModel;
use App\Models\PaymentModesModel;
use App\Models\InvoicesModel;
use App\Models\CreditNotesModel;
use App\Models\ProposalsModel;
use App\Models\EstimatesModel;

class UtilitiesController extends Controller
{
    protected $utilitiesModel;
    protected $paymentModesModel;
    protected $invoicesModel;
    protected $creditNotesModel;
    protected $proposalsModel;
    protected $estimatesModel;

    public function __construct(
        UtilitiesModel $utilitiesModel,
        PaymentModesModel $paymentModesModel,
        InvoicesModel $invoicesModel,
        CreditNotesModel $creditNotesModel,
        ProposalsModel $proposalsModel,
        EstimatesModel $estimatesModel
    ) {
        parent::__construct();
        $this->utilitiesModel = $utilitiesModel;
        $this->paymentModesModel = $paymentModesModel;
        $this->invoicesModel = $invoicesModel;
        $this->creditNotesModel = $creditNotesModel;
        $this->proposalsModel = $proposalsModel;
        $this->estimatesModel = $estimatesModel;
    }

    public function activityLog()
    {
        if (!is_admin()) {
            access_denied('Activity Log');
        }

        if (request()->ajax()) {
            return $this->utilitiesModel->getTableData('activity_log');
        }

        $data['title'] = _l('utility_activity_log');
        return view('admin.utilities.activity_log', $data);
    }

    public function pipeLog()
    {
        if (!is_admin()) {
            access_denied('Ticket Pipe Log');
        }

        if (request()->ajax()) {
            return $this->utilitiesModel->getTableData('ticket_pipe_log');
        }

        $data['title'] = _l('ticket_pipe_log');
        return view('admin.utilities.ticket_pipe_log', $data);
    }

    public function clearActivityLog()
    {
        if (!is_admin()) {
            access_denied('Clear activity log');
        }

        DB::table(db_prefix() . 'activity_log')->truncate();
        return redirect(admin_url('utilities/activity_log'));
    }

    public function clearPipeLog()
    {
        if (!is_admin()) {
            access_denied('Clear ticket pipe activity log');
        }

        DB::table(db_prefix() . 'tickets_pipe_log')->truncate();
        return redirect(admin_url('utilities/pipe_log'));
    }

    public function calendar()
    {
        if (request()->post() && request()->ajax()) {
            $data = request()->post();

            $success = $this->utilitiesModel->event($data);
            $message = '';

            if ($success) {
                $message = isset($data['eventid']) ? _l('event_updated') : _l('utility_calendar_event_added_successfully');
            }

            return response()->json([
                'success' => $success,
                'message' => $message,
            ]);
        }

        $data['google_ids_calendars'] = $this->misc_model->get_google_calendar_ids();
        $data['google_calendar_api']  = get_option('google_calendar_api_key');
        $data['title']                = _l('calendar');
        add_calendar_assets();

        return view('admin.utilities.calendar', $data);
    }

    public function getCalendarData()
    {
        $startDate = date('Y-m-d', strtotime(request()->get('start')));
        $endDate = date('Y-m-d', strtotime(request()->get('end')));

        return response()->json($this->utilitiesModel->getCalendarData($startDate, $endDate, '', '', request()->all()));
    }

    public function viewEvent($id)
    {
        $data['event'] = $this->utilitiesModel->getEvent($id);

        if (($data['event']->public == 1 && !is_staff_member())
            || ($data['event']->public == 0 && $data['event']->userid != get_staff_user_id())) {
            // Handle unauthorized access
        } else {
            return view('admin.utilities.event', $data);
        }
    }

    public function deleteEvent($id)
    {
        if (request()->ajax()) {
            $event = $this->utilitiesModel->getEventById($id);

            if ($event->userid != get_staff_user_id() && !is_admin()) {
                return response()->json(['success' => false]);
            }

            $success = $this->utilitiesModel->deleteEvent($id);
            $message = $success ? _l('utility_calendar_event_deleted_successfully') : '';

            return response()->json([
                'success' => $success,
                'message' => $message,
            ]);
        }
    }

    public function media()
    {
        $data['title'] = _l('media_files');
        $data['connector'] = admin_url() . '/utilities/media_connector';

        $mediaLocale = get_media_locale();
        app_scripts()->add('media-js', 'assets/plugins/elFinder/js/elfinder.min.js');

        if (file_exists(FCPATH . 'assets/plugins/elFinder/js/i18n/elfinder.' . $mediaLocale . '.js')
            && $mediaLocale != 'en') {
            app_scripts()->add('media-lang-js', 'assets/plugins/elFinder/js/i18n/elfinder.' . $mediaLocale . '.js');
        }

        return view('admin.utilities.media', $data);
    }

    public function mediaConnector()
    {
        $mediaFolder = $this->app->get_media_folder();
        $mediaPath = storage_path('app/' . $mediaFolder);

        if (!is_dir($mediaPath)) {
            mkdir($mediaPath, 0755, true);
        }

        if (!file_exists($mediaPath . '/index.html')) {
            $fp = fopen($mediaPath . '/index.html', 'w');
            if ($fp) {
                fclose($fp);
            }
        }

        $rootOptions = [
            'driver' => 'LocalFileSystem',
            'path'   => $mediaPath,
            'URL'    => Storage::url($mediaFolder) . '/',
            'uploadMaxSize' => get_option('media_max_file_size_upload') . 'M',
            'accessControl' => 'access_control_media',
            'uploadDeny'    => [
                'application/x-httpd-php',
                'application/php',
                'application/x-php',
                'text/php',
                'text/x-php',
                'application/x-httpd-php-source',
                'application/perl',
                'application/x-perl',
                'application/x-python',
                'application/python',
                'application/x-bytecode.python',
                'application/x-python-bytecode',
                'application/x-python-code',
                'wwwserver/shellcgi', // CGI
            ],
            'uploadAllow' => !request()->input('editor') ? [] : ['image', 'video'],
            'uploadOrder' => [
                'deny',
                'allow',
            ],
            'attributes' => [
                [
                    'pattern' => '/.tmb/',
                    'hidden'  => true,
                ],
                [
                    'pattern' => '/.quarantine/',
                    'hidden'  => true,
                ],
                [
                    'pattern' => '/public/',
                    'hidden'  => true,
                ],
            ],
        ];

        if (!is_admin()) {
            $user = DB::table(db_prefix() . 'staff')
                ->select('media_path_slug', 'staffid', 'firstname', 'lastname')
                ->where('staffid', get_staff_user_id())
                ->first();

            $path = set_realpath($mediaFolder . '/' . $user->media_path_slug);

            if (empty($user->media_path_slug)) {
                DB::table(db_prefix() . 'staff')
                    ->where('staffid', $user->staffid)
                    ->update(['media_path_slug' => slug_it($user->firstname . ' ' . $user->lastname)]);

                $user = DB::table(db_prefix() . 'staff')
                    ->select('media_path_slug', 'staffid', 'firstname', 'lastname')
                    ->where('staffid', get_staff_user_id())
                    ->first();

                $path = set_realpath($mediaFolder . '/' . $user->media_path_slug);
            }

            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }

            if (!file_exists($path . '/index.html')) {
                $fp = fopen($path . '/index.html', 'w');
                if ($fp) {
                    fclose($fp);
                }
            }

            array_push($rootOptions['attributes'], [
                'pattern' => '/.(' . $user->media_path_slug . '+)/', // Prevent deleting/renaming folder
                'read'    => true,
                'write'   => true,
                'locked'  => true,
            ]);

            $rootOptions['path'] = $path;
            $rootOptions['URL']  = Storage::url($mediaFolder . '/' . $user->media_path_slug) . '/';
        }

        $publicRootPath = $mediaFolder . '/public';
        $publicRootOptions = $rootOptions;
        $publicRootOptions['path'] = set_realpath($publicRootPath);
        $publicRootOptions['URL'] = Storage::url($mediaFolder) . '/public';
        unset($publicRootOptions['attributes'][3]);

        if (!is_dir($publicRootPath)) {
            mkdir($publicRootPath, 0755, true);
        }

        if (!file_exists($publicRootPath . '/index.html')) {
            $fp = fopen($publicRootPath . '/index.html', 'w');
            if ($fp) {
                fclose($fp);
            }
        }

        $opts = [
            'roots' => [
                $rootOptions,
                $publicRootOptions,
            ],
        ];

        $opts = hooks()->apply_filters('before_init_media', $opts);

        $connector = new elFinderConnector(new elFinder($opts));
        $connector->run();
    }

    public function bulkPdfExporter()
    {
        if (!has_permission('bulk_pdf_exporter', '', 'view')) {
            access_denied('bulk_pdf_exporter');
        }

        if (request()->post()) {
            $exportType = request()->post('export_type');

            $this->load->library('app_bulk_pdf_export', [
                'export_type'       => $exportType,
                'status'            => request()->post($exportType . '_export_status'),
                'date_from'         => request()->post('date-from'),
                'date_to'           => request()->post('date-to'),
                'payment_mode'      => request()->post('paymentmode'),
                'tag'               => request()->post('tag'),
                'redirect_on_error' => admin_url('utilities/bulk_pdf_exporter'),
            ]);

            $this->app_bulk_pdf_export->export();
        }

        $data['payment_modes'] = $this->paymentModesModel->get();
        $data['invoice_statuses'] = $this->invoicesModel->get_statuses();
        $data['credit_notes_statuses'] = $this->creditNotesModel->get_statuses();
        $data['proposal_statuses'] = $this->proposalsModel->get_statuses();
        $data['estimate_statuses'] = $this->estimatesModel->get_statuses();

        $features = [];

        if (has_permission('invoices', '', 'view')
            || has_permission('invoices', '', 'view_own')
            || get_option('allow_staff_view_invoices_assigned') == '1') {
            $features[] = [
                'feature' => 'invoices',
                'name'    => _l('bulk_export_pdf_invoices'),
            ];
        }

        if (has_permission('estimates', '', 'view')
            || has_permission('estimates', '', 'view_own')
            || get_option('allow_staff_view_estimates_assigned') == '1') {
            $features[] = [
                'feature' => 'estimates',
                'name'    => _l('bulk_export_pdf_estimates'),
            ];
        }

        if (has_permission('payments', '', 'view') || has_permission('invoices', '', 'view_own')) {
            $features[] = [
                'feature' => 'payments',
                'name'    => _l('bulk_export_pdf_payments'),
            ];
        }

        if (has_permission('credit_notes', '', 'view') || has_permission('credit_notes', '', 'view_own')) {
            $features[] = [
                'feature' => 'credit_notes',
                'name'    => _l('credit_notes'),
            ];
        }

        if (has_permission('proposals', '', 'view')
            || has_permission('proposals', '', 'view_own')
            || get_option('allow_staff_view_proposals_assigned') == '1') {
            $features[] = [
                'feature' => 'proposals',
                'name'    => _l('bulk_export_pdf_proposals'),
            ];
        }

        if (has_permission('expenses', '', 'view')
            || has_permission('expenses', '', 'view_own')) {
            $features[] = [
                'feature' => 'expenses',
                'name'    => _l('expenses'),
            ];
        }

        $data['bulk_pdf_export_available_features'] = hooks()->apply_filters(
            'bulk_pdf_export_available_features',
            $features
        );

        $data['title'] = _l('bulk_pdf_exporter');
        return view('admin.utilities.bulk_pdf_exporter', $data);
    }
}
