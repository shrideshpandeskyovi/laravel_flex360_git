<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DashboardModel;
use App\Models\DepartmentsModel;
use App\Models\TodoModel;
use App\Models\ContractsModel;
use App\Models\CurrenciesModel;
use App\Models\MiscModel;
use App\Models\AnnouncementsModel;
use App\Models\ProjectsModel;
use App\Models\UtilitiesModel;
use App\Models\EstimatesModel;
use App\Models\ProposalsModel;
use App\Services\TicketsReportByStaff;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Redirect;

class DashboardController extends Controller
{
    protected $dashboardModel;
    protected $departmentsModel;
    protected $todoModel;
    protected $contractsModel;
    protected $currenciesModel;
    protected $miscModel;
    protected $announcementsModel;
    protected $projectsModel;
    protected $utilitiesModel;
    protected $estimatesModel;
    protected $proposalsModel;

    public function __construct(
        DashboardModel $dashboardModel,
        DepartmentsModel $departmentsModel,
        TodoModel $todoModel,
        ContractsModel $contractsModel,
        CurrenciesModel $currenciesModel,
        MiscModel $miscModel,
        AnnouncementsModel $announcementsModel,
        ProjectsModel $projectsModel,
        UtilitiesModel $utilitiesModel,
        EstimatesModel $estimatesModel,
        ProposalsModel $proposalsModel
    ) {
        $this->dashboardModel = $dashboardModel;
        $this->departmentsModel = $departmentsModel;
        $this->todoModel = $todoModel;
        $this->contractsModel = $contractsModel;
        $this->currenciesModel = $currenciesModel;
        $this->miscModel = $miscModel;
        $this->announcementsModel = $announcementsModel;
        $this->projectsModel = $projectsModel;
        $this->utilitiesModel = $utilitiesModel;
        $this->estimatesModel = $estimatesModel;
        $this->proposalsModel = $proposalsModel;
    }

    public function index()
    {
        close_setup_menu();
        $data['departments'] = $this->departmentsModel->get();
        $data['todos'] = $this->todoModel->getTodoItems(0);
        $this->todoModel->setTodosLimit(5);
        $data['todos_finished'] = $this->todoModel->getTodoItems(1);
        $data['upcoming_events_next_week'] = $this->dashboardModel->getUpcomingEventsNextWeek();
        $data['upcoming_events'] = $this->dashboardModel->getUpcomingEvents();
        $data['title'] = __('dashboard_string');

        $data['expiringContracts'] = $this->contractsModel->getContractsAboutToExpire(auth()->id());

        $data['currencies'] = $this->currenciesModel->get();
        $data['base_currency'] = $this->currenciesModel->getBaseCurrency();
        $data['activity_log'] = $this->miscModel->getActivityLog();

        $tickets_awaiting_reply_by_status = $this->dashboardModel->ticketsAwaitingReplyByStatus();
        $tickets_awaiting_reply_by_department = $this->dashboardModel->ticketsAwaitingReplyByDepartment();

        $data['tickets_reply_by_status'] = json_encode($tickets_awaiting_reply_by_status);
        $data['tickets_awaiting_reply_by_department'] = json_encode($tickets_awaiting_reply_by_department);

        $data['tickets_reply_by_status_no_json'] = $tickets_awaiting_reply_by_status;
        $data['tickets_awaiting_reply_by_department_no_json'] = $tickets_awaiting_reply_by_department;

        $data['projects_status_stats'] = json_encode($this->dashboardModel->projectsStatusStats());
        $data['leads_status_stats'] = json_encode($this->dashboardModel->leadsStatusStats());
        $data['google_ids_calendars'] = $this->miscModel->getGoogleCalendarIds();
        $data['bodyclass'] = 'dashboard invoices-total-manual';
        $data['staff_announcements'] = $this->announcementsModel->get();
        $data['total_undismissed_announcements'] = $this->announcementsModel->getTotalUndismissedAnnouncements();

        $data['projects_activity'] = $this->projectsModel->getActivity('', hooks()->apply_filters('projects_activity_dashboard_limit', 20));
        add_calendar_assets();
        $data['estimate_statuses'] = $this->estimatesModel->getStatuses();
        $data['proposal_statuses'] = $this->proposalsModel->getStatuses();

        $wps_currency = 'undefined';
        if ($this->currenciesModel->isUsingMultipleCurrencies()) {
            $wps_currency = $data['base_currency']->id;
        }
        $data['weekly_payment_stats'] = json_encode($this->dashboardModel->getWeeklyPaymentsStatistics($wps_currency));

        $data['dashboard'] = true;

        $data['user_dashboard_visibility'] = get_staff_meta(auth()->id(), 'dashboard_widgets_visibility');

        if (!$data['user_dashboard_visibility']) {
            $data['user_dashboard_visibility'] = [];
        } else {
            $data['user_dashboard_visibility'] = unserialize($data['user_dashboard_visibility']);
        }
        $data['user_dashboard_visibility'] = json_encode($data['user_dashboard_visibility']);

        $data['tickets_report'] = [];
        if (is_admin()) {
            $data['tickets_report'] = (new TicketsReportByStaff())->filterBy('this_month');
        }
        $data['startedTimers'] = $this->miscModel->getStaffStartedTimers();
        $data = hooks()->apply_filters('before_dashboard_render', $data);

        if (!empty(session('downloadflex360')) && session('downloadflex360') == '1') {
            session()->forget('downloadflex360');
            return redirect()->route('candidates.downloadflex360');
        }

        return view('admin.dashboard.dashboard', $data);
    }

    public function weeklyPaymentsStatistics($currency)
    {
        if (request()->ajax()) {
            return response()->json($this->dashboardModel->getWeeklyPaymentsStatistics($currency));
        }
    }
    public function ticketWidget($type)
    {
        $data['tickets_report'] = (new TicketsReportByStaff())->filterBy($type);
        return view('admin.dashboard.widgets.tickets_report_table', $data);
    }

    public function empDashboard()
    {
        close_setup_menu();
        $data['departments'] = $this->departmentsModel->get();
        $data['todos'] = $this->todoModel->getTodoItems(0);
        $this->todoModel->setTodosLimit(5);
        $data['todos_finished'] = $this->todoModel->getTodoItems(1);
        $data['upcoming_events_next_week'] = $this->dashboardModel->getUpcomingEventsNextWeek();
        $data['upcoming_events'] = $this->dashboardModel->getUpcomingEvents();
        $data['title'] = __('dashboard_string');

        $data['expiringContracts'] = $this->contractsModel->getContractsAboutToExpire(auth()->id());

        $data['currencies'] = $this->currenciesModel->get();
        $data['base_currency'] = $this->currenciesModel->getBaseCurrency();
        $data['activity_log'] = $this->miscModel->getActivityLog();

        $tickets_awaiting_reply_by_status = $this->dashboardModel->ticketsAwaitingReplyByStatus();
        $tickets_awaiting_reply_by_department = $this->dashboardModel->ticketsAwaitingReplyByDepartment();

        $data['tickets_reply_by_status'] = json_encode($tickets_awaiting_reply_by_status);
        $data['tickets_awaiting_reply_by_department'] = json_encode($tickets_awaiting_reply_by_department);

        $data['tickets_reply_by_status_no_json'] = $tickets_awaiting_reply_by_status;
        $data['tickets_awaiting_reply_by_department_no_json'] = $tickets_awaiting_reply_by_department;

        $data['projects_status_stats'] = json_encode($this->dashboardModel->projectsStatusStats());
        $data['leads_status_stats'] = json_encode($this->dashboardModel->leadsStatusStats());
        $data['google_ids_calendars'] = $this->miscModel->getGoogleCalendarIds();
        $data['bodyclass'] = 'dashboard invoices-total-manual';
        $data['staff_announcements'] = $this->announcementsModel->get();
        $data['total_undismissed_announcements'] = $this->announcementsModel->getTotalUndismissedAnnouncements();

        $data['projects_activity'] = $this->projectsModel->getActivity('', hooks()->apply_filters('projects_activity_dashboard_limit', 20));
        add_calendar_assets();
        $data['estimate_statuses'] = $this->estimatesModel->getStatuses();
        $data['proposal_statuses'] = $this->proposalsModel->getStatuses();

        $wps_currency = 'undefined';
        if ($this->currenciesModel->isUsingMultipleCurrencies()) {
            $wps_currency = $data['base_currency']->id;
        }
        $data['weekly_payment_stats'] = json_encode($this->dashboardModel->getWeeklyPaymentsStatistics($wps_currency));

        $data['dashboard'] = true;

        $data['user_dashboard_visibility'] = get_staff_meta(auth()->id(), 'dashboard_widgets_visibility');

        if (!$data['user_dashboard_visibility']) {
            $data['user_dashboard_visibility'] = [];
        } else {
            $data['user_dashboard_visibility'] = unserialize($data['user_dashboard_visibility']);
        }
        $data['user_dashboard_visibility'] = json_encode($data['user_dashboard_visibility']);

        $data['tickets_report'] = [];
        if (is_admin()) {
            $data['tickets_report'] = (new TicketsReportByStaff())->filterBy('this_month');
        }
        $data['startedTimers'] = $this->miscModel->getStaffStartedTimers();
        $data = hooks()->apply_filters('before_dashboard_render', $data);
        
        return view('admin.empdashboard.empdashboard', $data);
    }
}
