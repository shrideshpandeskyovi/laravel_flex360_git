<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class SubscriptionsController extends Controller
{
    public function __construct()
    {
        $this->middleware('admin');
        $this->middleware('permission:subscriptions.view|subscriptions.view_own')->only('index');
        $this->middleware('permission:subscriptions.create')->only('create');
        $this->middleware('permission:subscriptions.view|subscriptions.view_own')->only('table');
        $this->middleware('permission:subscriptions.edit')->only('edit', 'send_to_email', 'cancel', 'resume');
        $this->middleware('permission:subscriptions.delete')->only('delete');
    }

    public function index()
    {
        close_setup_menu();

        $data['title'] = __('subscriptions');
        return view('admin.subscriptions.manage', $data);
    }

    public function table()
    {
        if (!has_permission('subscriptions', '', 'view') && !has_permission('subscriptions', '', 'view_own')) {
            ajax_access_denied();
        }
        app()->get_table_data('subscriptions');
    }

    public function create()
    {
        if (!has_permission('subscriptions', '', 'create')) {
            access_denied('Subscriptions Create');
        }

        $data['plans'] = [];

        try {
            $data['plans'] = app('App\Services\StripeSubscriptions')->getPlans();
            $stripeCore = app('App\Services\StripeCore');
            $data['stripe_tax_rates'] = $stripeCore->getTaxRates();
        } catch (Exception $e) {
            if (app('App\Services\StripeSubscriptions')->hasApiKey()) {
                $data['subscription_error'] = $e->getMessage();
            } else {
                $data['subscription_error'] = __('api_key_not_set_error_message', [
                    'link' => admin_url('settings?group=payment_gateways&tab=online_payments_stripe_tab'),
                ]);
            }
        }

        if (request()->has('customer_id')) {
            $data['customer_id'] = request()->input('customer_id');
        }

        $data['title'] = __('add_new', __('subscription_lowercase'));
        $data['taxes'] = app('App\Models\TaxesModel')->get();
        $data['currencies'] = app('App\Models\CurrenciesModel')->get();
        $data['bodyclass'] = 'subscription';

        return view('admin.subscriptions.subscription', $data);
    }

    public function edit($id)
    {
        if (!has_permission('subscriptions', '', 'view') && !has_permission('subscriptions', '', 'view_own')) {
            access_denied('Subscriptions View');
        }

        $subscription = app('App\Models\SubscriptionsModel')->getById($id);

        if (!$subscription || (!has_permission('subscriptions', '', 'view') && $subscription->created_from != get_staff_user_id())) {
            abort(404);
        }

        check_stripe_subscription_environment($subscription);

        $data = [];
        $stripeSubscriptionId = $subscription->stripe_subscription_id;

        if (request()->isMethod('post')) {
            if (!has_permission('subscriptions', '', 'edit')) {
                access_denied('Subscriptions Edit');
            }

            $update = [
                'name' => request()->input('name'),
                'description' => nl2br(request()->input('description')),
                'description_in_item' => request()->input('description_in_item') ? 1 : 0,
                'clientid' => request()->input('clientid'),
                'date' => request()->input('date') ? to_sql_date(request()->input('date')) : null,
                'project_id' => request()->input('project_id') ? request()->input('project_id') : 0,
                'stripe_plan_id' => request()->input('stripe_plan_id'),
                'terms' => nl2br(request()->input('terms')),
                'quantity' => request()->input('quantity'),
                'stripe_tax_id' => request()->input('stripe_tax_id') ? request()->input('stripe_tax_id') : false,
                'stripe_tax_id_2' => request()->input('stripe_tax_id_2') ? request()->input('stripe_tax_id_2') : false,
                'currency' => request()->input('currency'),
            ];

            if (!empty($stripeSubscriptionId)) {
                unset($update['clientid']);
                unset($update['date']);
            }

            try {
                $prorate = request()->input('prorate') ? true : false;
                app('App\Services\StripeSubscriptions')->updateSubscription($stripeSubscriptionId, $update, $subscription, $prorate);
            } catch (Exception $e) {
                set_alert('warning', $e->getMessage());
                redirect(admin_url('subscriptions/edit/' . $id));
            }

            $updated = app('App\Models\SubscriptionsModel')->update($id, $update);

            if ($updated) {
                set_alert('success', __('updated_successfully', __('subscription')));
            }
            redirect(admin_url('subscriptions/edit/' . $id));
        }

        try {
            $data['plans'] = [];
            $data['plans'] = app('App\Services\StripeSubscriptions')->getPlans();
            $stripeCore = app('App\Services\StripeCore');
            if (!empty($subscription->stripe_subscription_id)) {
                $data['stripeSubscription'] = app('App\Services\StripeSubscriptions')->getSubscription($subscription->stripe_subscription_id);
                if ($subscription->status != 'canceled' && $subscription->status !== 'incomplete_expired') {
                    $data['upcoming_invoice'] = app('App\Services\StripeSubscriptions')->getUpcomingInvoice($subscription->stripe_subscription_id);
                    $data['upcoming_invoice'] = subscription_invoice_preview_data($subscription, $data['upcoming_invoice'], $data['stripeSubscription']);
                    if (!isset($data['upcoming_invoice']->include_shipping)) {
                        $data['upcoming_invoice']->include_shipping = 0;
                    }
                }
            }
        } catch (Exception $e) {
            if (app('App\Services\StripeSubscriptions')->hasApiKey()) {
                $data['subscription_error'] = $e->getMessage();
            } else {
                $data['subscription_error'] = check_for_links(__('api_key_not_set_error_message', [
                    'link' => admin_url('settings?group=payment_gateways&tab=online_payments_stripe_tab'),
                ]));
            }
        }

        $data = array_merge($data, prepare_mail_preview_data('subscription_send_to_customer', $subscription->clientid));

        $data['child_invoices'] = app('App\Models\SubscriptionsModel')->getChildInvoices($id);
        $data['subscription'] = $subscription;
        $data['title'] = $data['subscription']->name;
        $data['taxes'] = app('App\Models\TaxesModel')->get();
        $data['currencies'] = app('App\Models\CurrenciesModel')->get();
        $data['bodyclass'] = 'subscription no-calculate-total';

        return view('admin.subscriptions.subscription', $data);
    }

    public function send_to_email($id)
    {
        if (!has_permission('subscriptions', '', 'view')) {
            access_denied('Subscription Send To Email');
        }

        if (request()->isMethod('post')) {
            $success = app('App\Models\SubscriptionsModel')->sendEmailTemplate($id, request()->input('cc'));
            if ($success) {
                set_alert('success', __('subscription_sent_to_email_success'));
            } else {
                set_alert('danger', __('subscription_sent_to_email_fail'));
            }
        }
        redirect(admin_url('subscriptions/edit/' . $id));
    }

    public function cancel($id)
    {
        if (!has_permission('subscriptions', '', 'edit')) {
            access_denied('Cancel Subscription');
        }

        $subscription = app('App\Models\SubscriptionsModel')->getById($id);

        if (!$subscription) {
            abort(404);
        }

        try {
            $type = request()->input('type');
            $ends_at = time();
            if ($type == 'immediately') {
                app('App\Services\StripeSubscriptions')->cancel($subscription->stripe_subscription_id);
            } elseif ($type == 'at_period_end') {
                $ends_at = app('App\Services\StripeSubscriptions')->cancelAtEndOfBillingPeriod($subscription->stripe_subscription_id);
            } else {
                throw new Exception('Invalid Cancelation Type', 1);
            }

            $update = ['ends_at' => $ends_at];

            if ($type == 'immediately') {
                $update['status'] = 'canceled';
            }
            app('App\Models\SubscriptionsModel')->update($id, $update);

            set_alert('success', __('subscription_canceled'));
        } catch (Exception $e) {
            set_alert('danger', $e->getMessage());
        }

        redirect(admin_url('subscriptions/edit/' . $id));
    }

    public function sync()
    {
        if (!is_admin()) {
            access_denied('Sync subscriptions');
        }

        $stripeSubscriptionsSynchronizer = app('App\Services\StripeSubscriptionsSynchronizer');

        echo '<a href="' . admin_url('subscriptions') . '">Go Back</a><br /><br />';

        $stripeSubscriptionsSynchronizer->sync();
    }

    public function resume($id)
    {
        if (!has_permission('subscriptions', '', 'edit')) {
            access_denied('Resume Subscription');
        }

        $subscription = app('App\Models\SubscriptionsModel')->getById($id);

        if (!$subscription) {
            abort(404);
        }

        try {
            app('App\Services\StripeSubscriptions')->resume($subscription->stripe_subscription_id, $subscription->stripe_plan_id);
            app('App\Models\SubscriptionsModel')->update($id, ['ends_at' => null]);
            set_alert('success', __('subscription_resumed'));
        } catch (Exception $e) {
            set_alert('danger', $e->getMessage());
        }

        redirect(admin_url('subscriptions/edit/' . $id));
    }

    public function delete($id)
    {
        if (!has_permission('subscriptions', '', 'delete')) {
            access_denied('Subscriptions Delete');
        }

        $subscriptionModel = app('App\Models\SubscriptionsModel');
        $subscription = $subscriptionModel->delete($id);

        if ($subscription) {
            if (!empty($subscription->stripe_subscription_id)) {
                try {
                    app('App\Services\StripeSubscriptions')->cancel($subscription->stripe_subscription_id);
                } catch (Exception $e) {
                }
            }
            set_alert('success', __('deleted', __('subscription')));
        } else {
            set_alert('warning', __('problem_deleting', __('subscription')));
        }

        if (strpos(url()->previous(), 'clients/') !== false) {
            return redirect(url()->previous());
        } else {
            return redirect(admin_url('subscriptions'));
        }
    }
}
