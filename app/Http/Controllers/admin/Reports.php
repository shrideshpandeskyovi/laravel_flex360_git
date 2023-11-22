use Illuminate\Support\Facades\DB;
use App\Models\KnowledgeBaseModel; // Make sure to adjust the namespace as per your Laravel project structure
use App\Models\LeadsModel;
use App\Models\InvoicesModel;
use App\Models\EstimatesModel;
use App\Models\ProposalsModel;
use App\Models\CreditNotesModel;

class ReportsController extends AdminController
{
    private $ci;

    public function __construct()
    {
        parent::__construct();

        if (!hasPermission('reports', '', 'view')) {
            accessDenied('reports');
        }

        $this->ci = app('db');
    }

    public function index()
    {
        return redirect(adminURL());
    }

    public function knowledgeBaseArticles()
    {
        $knowledgeBaseModel = new KnowledgeBaseModel();
        $data['groups'] = $knowledgeBaseModel->getKbg();
        $data['title']  = _l('kb_reports');
        return view('admin.reports.knowledge_base_articles', $data);
    }

    public function leads()
    {
        $type = 'leads';

        if (request()->input('type')) {
            $type = $type . '_' . request()->input('type');
            $data['leads_staff_report'] = json_encode((new ReportsModel())->leadsStaffReport());
        }

        $leadsModel = new LeadsModel();
        $data['statuses']               = $leadsModel->getStatus();
        $data['leads_this_week_report'] = json_encode((new ReportsModel())->leadsThisWeekReport());
        $data['leads_sources_report']   = json_encode((new ReportsModel())->leadsSourcesReport());
        
        return view('admin.reports.' . $type, $data);
    }

    public function sales()
    {
        $data['mysqlVersion'] = DB::select('SELECT VERSION() as version')[0];
        $data['sqlMode']      = DB::select('SELECT @@sql_mode as mode')[0];

        if (isUsingMultipleCurrencies() || isUsingMultipleCurrencies('creditnotes') || isUsingMultipleCurrencies('estimates') || isUsingMultipleCurrencies('proposals')) {
            $currenciesModel = new CurrenciesModel();
            $data['currencies'] = $currenciesModel->get();
        }

        $invoicesModel = new InvoicesModel();
        $estimatesModel = new EstimatesModel();
        $proposalsModel = new ProposalsModel();
        $creditNotesModel = new CreditNotesModel();

        $data['credit_notes_statuses'] = $creditNotesModel->getStatuses();
        $data['invoice_statuses']      = $invoicesModel->getStatuses();
        $data['estimate_statuses']     = $estimatesModel->getStatuses();
        $data['payments_years']        = (new ReportsModel())->getDistinctPaymentsYears();
        $data['estimates_sale_agents'] = $estimatesModel->getSaleAgents();

        $data['invoices_sale_agents'] = $invoicesModel->getSaleAgents();

        $data['proposals_sale_agents'] = $proposalsModel->getSaleAgents();
        $data['proposals_statuses']    = $proposalsModel->getStatuses();

        $data['invoice_taxes']     = $this->distinctTaxes('invoice');
        $data['estimate_taxes']    = $this->distinctTaxes('estimate');
        $data['proposal_taxes']    = $this->distinctTaxes('proposal');
        $data['credit_note_taxes'] = $this->distinctTaxes('credit_note');

        $data['title'] = _l('sales_reports');
        return view('admin.reports.sales', $data);
    }

    private function distinctTaxes($relType)
    {
        return $this->ci->table(dbPrefix() . 'item_tax')
            ->distinct()
            ->select('taxname', 'taxrate')
            ->where('rel_type', $relType)
            ->get()
            ->toArray();
    }
    public function customersReport()
    {
        if (request()->ajax()) {
            $currenciesModel = new CurrenciesModel();
            $select = [
                getClientCompanySql(),
                '(SELECT COUNT(clientid) FROM ' . dbPrefix() . 'invoices WHERE ' . dbPrefix() . 'invoices.clientid = ' . dbPrefix() . 'clients.userid AND status != 5)',
                '(SELECT SUM(subtotal) - SUM(discount_total) FROM ' . dbPrefix() . 'invoices WHERE ' . dbPrefix() . 'invoices.clientid = ' . dbPrefix() . 'clients.userid AND status != 5)',
                '(SELECT SUM(total) FROM ' . dbPrefix() . 'invoices WHERE ' . dbPrefix() . 'invoices.clientid = ' . dbPrefix() . 'clients.userid AND status != 5)',
            ];

            $customDateSelect = $this->getWhereReportPeriod();
            if ($customDateSelect != '') {
                $i = 0;
                foreach ($select as $_select) {
                    if ($i !== 0) {
                        $_temp = substr($_select, 0, -1);
                        $_temp .= ' ' . $customDateSelect . ')';
                        $select[$i] = $_temp;
                    }
                    $i++;
                }
            }

            $byCurrency = request()->input('report_currency');
            $currency = $currenciesModel->getBaseCurrency();
            if ($byCurrency) {
                $i = 0;
                foreach ($select as $_select) {
                    if ($i !== 0) {
                        $_temp = substr($_select, 0, -1);
                        $_temp .= ' AND currency =' . $this->ci->db->escapeStr($byCurrency) . ')';
                        $select[$i] = $_temp;
                    }
                    $i++;
                }
                $currency = $currenciesModel->get($byCurrency);
            }

            $aColumns = $select;
            $sIndexColumn = 'userid';
            $sTable = dbPrefix() . 'clients';
            $where = [];

            $result = dataTablesInit($aColumns, $sIndexColumn, $sTable, [], $where, [
                'userid',
            ]);

            $output = $result['output'];
            $rResult = $result['rResult'];

            $x = 0;
            foreach ($rResult as $aRow) {
                $row = [];
                for ($i = 0; $i < count($aColumns); $i++) {
                    if (strpos($aColumns[$i], 'as') !== false && !isset($aRow[$aColumns[$i]])) {
                        $_data = $aRow[strafter($aColumns[$i], 'as ')];
                    } else {
                        $_data = $aRow[$aColumns[$i]];
                    }

                    if ($i == 0) {
                        $_data = '<a href="' . adminURL('clients/client/' . $aRow['userid']) . '" target="_blank">' . $aRow['company'] . '</a>';
                    } elseif ($aColumns[$i] == $select[2] || $aColumns[$i] == $select[3]) {
                        if ($_data == null) {
                            $_data = 0;
                        }
                        $_data = appFormatMoney($_data, $currency->name);
                    }

                    $row[] = $_data;
                }

                $output['aaData'][] = $row;
                $x++;
            }

            echo json_encode($output);
            die();
        }
    }

    public function paymentsReceived()
    {
        if (request()->ajax()) {
            $currenciesModel = new CurrenciesModel();
            $paymentModesModel = new PaymentModesModel();
            $paymentGateways = $paymentModesModel->getPaymentGateways(true);

            $select = [
                dbPrefix() . 'invoicepaymentrecords.id',
                dbPrefix() . 'invoicepaymentrecords.date',
                'invoiceid',
                getClientCompanySql(),
                'paymentmode',
                'transactionid',
                'note',
                'amount',
            ];

            $where = [
                'AND status != 5',
            ];

            $customDateSelect = $this->getWhereReportPeriod(dbPrefix() . 'invoicepaymentrecords.date');
            if ($customDateSelect != '') {
                array_push($where, $customDateSelect);
            }

            $byCurrency = request()->input('report_currency');
            if ($byCurrency) {
                $currency = $currenciesModel->get($byCurrency);
                array_push($where, 'AND currency=' . $this->ci->db->escapeStr($byCurrency));
            } else {
                $currency = $currenciesModel->getBaseCurrency();
            }

            $aColumns = $select;
            $sIndexColumn = 'id';
            $sTable = dbPrefix() . 'invoicepaymentrecords';
            $join = [
                'JOIN ' . dbPrefix() . 'invoices ON ' . dbPrefix() . 'invoices.id = ' . dbPrefix() . 'invoicepaymentrecords.invoiceid',
                'LEFT JOIN ' . dbPrefix() . 'clients ON ' . dbPrefix() . 'clients.userid = ' . dbPrefix() . 'invoices.clientid',
                'LEFT JOIN ' . dbPrefix() . 'payment_modes ON ' . dbPrefix() . 'payment_modes.id = ' . dbPrefix() . 'invoicepaymentrecords.paymentmode',
            ];

            $result = dataTablesInit($aColumns, $sIndexColumn, $sTable, $join, $where, [
                'number',
                'clientid',
                dbPrefix() . 'payment_modes.name',
                dbPrefix() . 'payment_modes.id as paymentmodeid',
                'paymentmethod',
                'deleted_customer_name',
            ]);

            $output = $result['output'];
            $rResult = $result['rResult'];

            $footerData['total_amount'] = 0;
            foreach ($rResult as $aRow) {
                $row = [];
                for ($i = 0; $i < count($aColumns); $i++) {
                    if (strpos($aColumns[$i], 'as') !== false && !isset($aRow[$aColumns[$i]])) {
                        $_data = $aRow[strafter($aColumns[$i], 'as ')];
                    } else {
                        $_data = $aRow[$aColumns[$i]];
                    }

                    if ($aColumns[$i] == 'paymentmode') {
                        $_data = $aRow['name'];
                        if (is_null($aRow['paymentmodeid'])) {
                            foreach ($paymentGateways as $gateway) {
                                if ($aRow['paymentmode'] == $gateway['id']) {
                                    $_data = $gateway['name'];
                                }
                            }
                        }
                        if (!empty($aRow['paymentmethod'])) {
                            $_data .= ' - ' . $aRow['paymentmethod'];
                        }
                    } elseif ($aColumns[$i] == dbPrefix() . 'invoicepaymentrecords.id') {
                        $_data = '<a href="' . adminURL('payments/payment/' . $_data) . '" target="_blank">' . $_data . '</a>';
                    } elseif ($aColumns[$i] == dbPrefix() . 'invoicepaymentrecords.date') {
                        $_data = _d($_data);
                    } elseif ($aColumns[$i] == 'invoiceid') {
                        $_data = '<a href="' . adminURL('invoices/list_invoices/' . $aRow[$aColumns[$i]]) . '" target="_blank">' . formatInvoiceNumber($aRow['invoiceid']) . '</a>';
                    } elseif ($i == 3) {
                        if (empty($aRow['deleted_customer_name'])) {
                            $_data = '<a href="' . adminURL('clients/client/' . $aRow['clientid']) . '" target="_blank">' . $aRow['company'] . '</a>';
                        } else {
                            $row[] = $aRow['deleted_customer_name'];
                        }
                    } elseif ($aColumns[$i] == 'amount') {
                        $footerData['total_amount'] += $_data;
                        $_data = appFormatMoney($_data, $currency->name);
                    }

                    $row[] = $_data;
                }

                $output['aaData'][] = $row;
            }

            $footerData['total_amount'] = appFormatMoney($footerData['total_amount'], $currency->name);
            $output['sums'] = $footerData;
            echo json_encode($output);
            die();
        }
    }
    public function proposalsReport()
    {
        if (request()->ajax()) {
            $currenciesModel = new CurrenciesModel();
            $proposalsModel = new ProposalsModel();
            $itemTaxModel = new ItemTaxModel();
            $itemableModel = new ItemableModel(); // Adjust as per your Laravel project structure

            $proposalsTaxes = $this->distinctTaxes('proposal');
            $totalTaxesColumns = count($proposalsTaxes);

            $select = [
                'id',
                'subject',
                'proposal_to',
                'date',
                'open_till',
                'subtotal',
                'total',
                'total_tax',
                'discount_total',
                'adjustment',
                'status',
            ];

            $proposalsTaxesSelect = array_reverse($proposalsTaxes);

            foreach ($proposalsTaxesSelect as $key => $tax) {
                array_splice($select, 8, 0, "(
                    SELECT CASE
                    WHEN discount_percent != 0 AND discount_type = 'before_tax' THEN ROUND(SUM((qty*rate/100*$item_tax.taxrate) - (qty*rate/100*$item_tax.taxrate * discount_percent/100)), " . getDecimalPlaces() . ")
                    WHEN discount_total != 0 AND discount_type = 'before_tax' THEN ROUND(SUM((qty*rate/100*$item_tax.taxrate) - (qty*rate/100*$item_tax.taxrate * (discount_total/subtotal*100) / 100)), " . getDecimalPlaces() . ")
                    ELSE ROUND(SUM(qty*rate/100*$item_tax.taxrate), " . getDecimalPlaces() . ")
                    END
                    FROM $itemableModel->getTable()
                    INNER JOIN $item_tax->getTable() ON $item_tax->itemid=$itemable->id
                    WHERE $itemable->rel_type='proposal' AND taxname='{$tax['taxname']}' AND taxrate='{$tax['taxrate']}' AND $itemable->rel_id=$proposals->id) as total_tax_single_$key");
            }

            $where = [];
            $customDateSelect = $this->getWhereReportPeriod();
            if ($customDateSelect != '') {
                array_push($where, $customDateSelect);
            }

            if (request()->input('proposal_status')) {
                $statuses = request()->input('proposal_status');
                $_statuses = [];
                if (is_array($statuses)) {
                    foreach ($statuses as $status) {
                        if ($status != '') {
                            array_push($_statuses, $this->ci->db->escapeStr($status));
                        }
                    }
                }
                if (count($_statuses) > 0) {
                    array_push($where, 'AND status IN (' . implode(', ', $_statuses) . ')');
                }
            }

            if (request()->input('proposals_sale_agents')) {
                $agents = request()->input('proposals_sale_agents');
                $_agents = [];
                if (is_array($agents)) {
                    foreach ($agents as $agent) {
                        if ($agent != '') {
                            array_push($_agents, $this->ci->db->escapeStr($agent));
                        }
                    }
                }
                if (count($_agents) > 0) {
                    array_push($where, 'AND assigned IN (' . implode(', ', $_agents) . ')');
                }
            }

            $byCurrency = request()->input('report_currency');
            if ($byCurrency) {
                $currency = $currenciesModel->get($byCurrency);
                array_push($where, 'AND currency=' . $this->ci->db->escapeStr($byCurrency));
            } else {
                $currency = $currenciesModel->getBaseCurrency();
            }

            $aColumns = $select;
            $sIndexColumn = 'id';
            $sTable = $proposalsModel->getTable();
            $join = [];

            $result = dataTablesInit($aColumns, $sIndexColumn, $sTable, $join, $where, [
                'rel_id',
                'rel_type',
                'discount_percent',
            ]);

            $output = $result['output'];
            $rResult = $result['rResult'];

            $footerData = [
                'total' => 0,
                'subtotal' => 0,
                'total_tax' => 0,
                'discount_total' => 0,
                'adjustment' => 0,
            ];

            foreach ($proposalsTaxes as $key => $tax) {
                $footerData['total_tax_single_' . $key] = 0;
            }

            foreach ($rResult as $aRow) {
                $row = [];

                $row[] = '<a href="' . adminURL('proposals/list_proposals/' . $aRow['id']) . '" target="_blank">' . formatProposalNumber($aRow['id']) . '</a>';

                $row[] = '<a href="' . adminURL('proposals/list_proposals/' . $aRow['id']) . '" target="_blank">' . $aRow['subject'] . '</a>';

                if ($aRow['rel_type'] == 'lead') {
                    $row[] = '<a href="#" onclick="init_lead(' . $aRow['rel_id'] . ');return false;" target="_blank" data-toggle="tooltip" data-title="' . _l('lead') . '">' . $aRow['proposal_to'] . '</a>' . '<span class="hide">' . _l('lead') . '</span>';
                } elseif ($aRow['rel_type'] == 'customer') {
                    $row[] = '<a href="' . adminURL('clients/client/' . $aRow['rel_id']) . '" target="_blank" data-toggle="tooltip" data-title="' . _l('client') . '">' . $aRow['proposal_to'] . '</a>' . '<span class="hide">' . _l('client') . '</span>';
                } else {
                    $row[] = '';
                }

                $row[] = _d($aRow['date']);

                $row[] = _d($aRow['open_till']);

                $row[] = appFormatMoney($aRow['subtotal'], $currency->name);
                $footerData['subtotal'] += $aRow['subtotal'];

                $row[] = appFormatMoney($aRow['total'], $currency->name);
                $footerData['total'] += $aRow['total'];

                $row[] = appFormatMoney($aRow['total_tax'], $currency->name);
                $footerData['total_tax'] += $aRow['total_tax'];

                $t = $totalTaxesColumns - 1;
                $i = 0;
                foreach ($proposalsTaxes as $tax) {
                    $row[] = appFormatMoney(($aRow['total_tax_single_' . $t] == null ? 0 : $aRow['total_tax_single_' . $t]), $currency->name);
                    $footerData['total_tax_single_' . $i] += ($aRow['total_tax_single_' . $t] == null ? 0 : $aRow['total_tax_single_' . $t]);
                    $t--;
                    $i++;
                }

                $row[] = appFormatMoney($aRow['discount_total'], $currency->name);
                $footerData['discount_total'] += $aRow['discount_total'];

                $row[] = appFormatMoney($aRow['adjustment'], $currency->name);
                $footerData['adjustment'] += $aRow['adjustment'];

                $row[] = formatProposalStatus($aRow['status']);
                $output['aaData'][] = $row;
            }

            foreach ($footerData as $key => $total) {
                $footerData[$key] = appFormatMoney($total, $currency->name);
            }

            $output['sums'] = $footerData;
            return response()->json($output);
        }
    }
    
    public function estimatesReport()
    {
        if (request()->ajax()) {
            $currenciesModel = new CurrenciesModel();
            $estimatesModel = new EstimatesModel();
            $itemTaxModel = new ItemTaxModel();
            $itemableModel = new ItemableModel(); // Adjust as per your Laravel project structure

            $estimateTaxes = $this->distinctTaxes('estimate');
            $totalTaxesColumns = count($estimateTaxes);

            $select = [
                'number',
                getSqlSelectClientCompany(),
                'invoiceid',
                'YEAR(date) as year',
                'date',
                'expirydate',
                'subtotal',
                'total',
                'total_tax',
                'discount_total',
                'adjustment',
                'reference_no',
                'status',
            ];

            $estimatesTaxesSelect = array_reverse($estimateTaxes);

            foreach ($estimatesTaxesSelect as $key => $tax) {
                array_splice($select, 9, 0, "(
                    SELECT CASE
                    WHEN discount_percent != 0 AND discount_type = 'before_tax' THEN ROUND(SUM((qty*rate/100*$item_tax.taxrate) - (qty*rate/100*$item_tax.taxrate * discount_percent/100)), " . getDecimalPlaces() . ")
                    WHEN discount_total != 0 AND discount_type = 'before_tax' THEN ROUND(SUM((qty*rate/100*$item_tax.taxrate) - (qty*rate/100*$item_tax.taxrate * (discount_total/subtotal*100) / 100)), " . getDecimalPlaces() . ")
                    ELSE ROUND(SUM(qty*rate/100*$item_tax.taxrate), " . getDecimalPlaces() . ")
                    END
                    FROM $itemableModel->getTable()
                    INNER JOIN $item_tax->getTable() ON $item_tax->itemid=$itemable->id
                    WHERE $itemable->rel_type='estimate' AND taxname='{$tax['taxname']}' AND taxrate='{$tax['taxrate']}' AND $itemable->rel_id=$estimates->id) as total_tax_single_$key");
            }

            $where = [];
            $customDateSelect = $this->getWhereReportPeriod();
            if ($customDateSelect != '') {
                array_push($where, $customDateSelect);
            }

            if (request()->input('estimate_status')) {
                $statuses = request()->input('estimate_status');
                $_statuses = [];
                if (is_array($statuses)) {
                    foreach ($statuses as $status) {
                        if ($status != '') {
                            array_push($_statuses, $this->ci->db->escapeStr($status));
                        }
                    }
                }
                if (count($_statuses) > 0) {
                    array_push($where, 'AND status IN (' . implode(', ', $_statuses) . ')');
                }
            }

            if (request()->input('sale_agent_estimates')) {
                $agents = request()->input('sale_agent_estimates');
                $_agents = [];
                if (is_array($agents)) {
                    foreach ($agents as $agent) {
                        if ($agent != '') {
                            array_push($_agents, $this->ci->db->escapeStr($agent));
                        }
                    }
                }
                if (count($_agents) > 0) {
                    array_push($where, 'AND sale_agent IN (' . implode(', ', $_agents) . ')');
                }
            }

            $byCurrency = request()->input('report_currency');
            if ($byCurrency) {
                $currency = $currenciesModel->get($byCurrency);
                array_push($where, 'AND currency=' . $this->ci->db->escapeStr($byCurrency));
            } else {
                $currency = $currenciesModel->getBaseCurrency();
            }

            $aColumns = $select;
            $sIndexColumn = 'id';
            $sTable = $estimatesModel->getTable();
            $join = [
                'LEFT JOIN ' . db_prefix() . 'clients ON ' . db_prefix() . 'clients.userid = ' . db_prefix() . 'estimates.clientid',
            ];

            $result = dataTablesInit($aColumns, $sIndexColumn, $sTable, $join, $where, [
                'userid',
                'clientid',
                db_prefix() . 'estimates.id',
                'discount_percent',
                'deleted_customer_name',
            ]);

            $output = $result['output'];
            $rResult = $result['rResult'];

            $footerData = [
                'total' => 0,
                'subtotal' => 0,
                'total_tax' => 0,
                'discount_total' => 0,
                'adjustment' => 0,
            ];

            foreach ($estimateTaxes as $key => $tax) {
                $footerData['total_tax_single_' . $key] = 0;
            }

            foreach ($rResult as $aRow) {
                $row = [];

                $row[] = '<a href="' . adminURL('estimates/list_estimates/' . $aRow['id']) . '" target="_blank">' . formatEstimateNumber($aRow['id']) . '</a>';

                if (empty($aRow['deleted_customer_name'])) {
                    $row[] = '<a href="' . adminURL('clients/client/' . $aRow['userid']) . '" target="_blank">' . $aRow['company'] . '</a>';
                } else {
                    $row[] = $aRow['deleted_customer_name'];
                }

                if ($aRow['invoiceid'] === null) {
                    $row[] = '';
                } else {
                    $row[] = '<a href="' . adminURL('invoices/list_invoices/' . $aRow['invoiceid']) . '" target="_blank">' . formatInvoiceNumber($aRow['invoiceid']) . '</a>';
                }

                $row[] = $aRow['year'];

                $row[] = _d($aRow['date']);

                $row[] = _d($aRow['expirydate']);

                $row[] = appFormatMoney($aRow['subtotal'], $currency->name);
                $footerData['subtotal'] += $aRow['subtotal'];

                $row[] = appFormatMoney($aRow['total'], $currency->name);
                $footerData['total'] += $aRow['total'];

                $row[] = appFormatMoney($aRow['total_tax'], $currency->name);
                $footerData['total_tax'] += $aRow['total_tax'];

                $t = $totalTaxesColumns - 1;
                $i = 0;
                foreach ($estimateTaxes as $tax) {
                    $row[] = appFormatMoney(($aRow['total_tax_single_' . $t] == null ? 0 : $aRow['total_tax_single_' . $t]), $currency->name);
                    $footerData['total_tax_single_' . $i] += ($aRow['total_tax_single_' . $t] == null ? 0 : $aRow['total_tax_single_' . $t]);
                    $t--;
                    $i++;
                }

                $row[] = appFormatMoney($aRow['discount_total'], $currency->name);
                $footerData['discount_total'] += $aRow['discount_total'];

                $row[] = appFormatMoney($aRow['adjustment'], $currency->name);
                $footerData['adjustment'] += $aRow['adjustment'];

                $row[] = $aRow['reference_no'];

                $row[] = formatEstimateStatus($aRow['status']);

                $output['aaData'][] = $row;
            }
            foreach ($footerData as $key => $total) {
                $footerData[$key] = appFormatMoney($total, $currency->name);
            }
            $output['sums'] = $footerData;
            return response()->json($output);
        }
    }

    private function getWhereReportPeriod($field = 'date')
    {
        $monthsReport = request()->input('report_months');
        $customDateSelect = '';
        if ($monthsReport != '') {
            if (is_numeric($monthsReport)) {
                // Last month
                if ($monthsReport == '1') {
                    $beginMonth = date('Y-m-01', strtotime('first day of last month'));
                    $endMonth = date('Y-m-t', strtotime('last day of last month'));
                } else {
                    $monthsReport = (int) $monthsReport;
                    $monthsReport--;
                    $beginMonth = date('Y-m-01', strtotime("-$monthsReport MONTH"));
                    $endMonth = date('Y-m-t');
                }

                $customDateSelect = 'AND (' . $field . ' BETWEEN "' . $beginMonth . '" AND "' . $endMonth . '")';
            } elseif ($monthsReport == 'this_month') {
                $customDateSelect = 'AND (' . $field . ' BETWEEN "' . date('Y-m-01') . '" AND "' . date('Y-m-t') . '")';
            } elseif ($monthsReport == 'this_year') {
                $customDateSelect = 'AND (' . $field . ' BETWEEN "' .
                    date('Y-m-d', strtotime(date('Y-01-01'))) .
                    '" AND "' .
                    date('Y-m-d', strtotime(date('Y-12-31'))) . '")';
            } elseif ($monthsReport == 'last_year') {
                $customDateSelect = 'AND (' . $field . ' BETWEEN "' .
                    date('Y-m-d', strtotime(date(date('Y', strtotime('last year')) . '-01-01'))) .
                    '" AND "' .
                    date('Y-m-d', strtotime(date(date('Y', strtotime('last year')) . '-12-31'))) . '")';
            } elseif ($monthsReport == 'custom') {
                $fromDate = toSqlDate(request()->input('report_from'));
                $toDate = toSqlDate(request()->input('report_to'));
                if ($fromDate == $toDate) {
                    $customDateSelect = 'AND ' . $field . ' = "' . $this->ci->db->escapeStr($fromDate) . '"';
                } else {
                    $customDateSelect = 'AND (' . $field . ' BETWEEN "' . $this->ci->db->escapeStr($fromDate) . '" AND "' . $this->ci->db->escapeStr($toDate) . '")';
                }
            }
        }

        return $customDateSelect;
    }
    public function items()
    {
        if (request()->ajax()) {
            $currenciesModel = new CurrenciesModel();

            $v = DB::select('SELECT VERSION() as version')[0];
            $isMysql57 = $v && strpos($v->version, '5.7') !== false;

            $aColumns = $isMysql57
                ? ['ANY_VALUE(description) as description', 'ANY_VALUE((SUM(itemable.qty))) as quantity_sold', 'ANY_VALUE(SUM(rate*qty)) as rate', 'ANY_VALUE(AVG(rate*qty)) as avg_price']
                : ['description as description', '(SUM(itemable.qty)) as quantity_sold', 'SUM(rate*qty) as rate', 'AVG(rate*qty) as avg_price'];

            $sIndexColumn = 'id';
            $sTable = 'itemable';
            $join = ['JOIN invoices ON invoices.id = itemable.rel_id'];

            $where = ['AND rel_type="invoice"', 'AND status != 5', 'AND status=2'];

            $customDateSelect = $this->getWhereReportPeriod();
            if ($customDateSelect != '') {
                array_push($where, $customDateSelect);
            }

            $byCurrency = Input::post('report_currency');
            if ($byCurrency) {
                $currency = $currenciesModel->find($byCurrency);
                array_push($where, 'AND currency=' . $this->db->escapeStr($byCurrency));
            } else {
                $currency = $currenciesModel->getBaseCurrency();
            }

            $result = DataTableHelper::init($aColumns, $sIndexColumn, $sTable, $join, $where, [], 'GROUP by description');

            $output = $result['output'];
            $rResult = $result['rResult'];

            $footerData = [
                'total_amount' => 0,
                'total_qty'    => 0,
            ];

            foreach ($rResult as $aRow) {
                $row = [];

                $row[] = $aRow['description'];
                $row[] = $aRow['quantity_sold'];
                $row[] = app('money')->format($aRow['rate'], $currency->name);
                $row[] = app('money')->format($aRow['avg_price'], $currency->name);
                $footerData['total_amount'] += $aRow['rate'];
                $footerData['total_qty'] += $aRow['quantity_sold'];
                $output['aaData'][] = $row;
            }

            $footerData['total_amount'] = app('money')->format($footerData['total_amount'], $currency->name);

            $output['sums'] = $footerData;
            return response()->json($output);
        }
    }
    public function creditNotes()
    {
        if (request()->ajax()) {
            $creditNoteTaxes = $this->distinctTaxes('credit_note');
            $totalTaxesColumns = count($creditNoteTaxes);

            $currenciesModel = new CurrenciesModel();

            $select = [
                'number',
                'date',
                InvoiceHelper::getSqlSelectClientCompany(),
                'reference_no',
                'subtotal',
                'total',
                'total_tax',
                'discount_total',
                'adjustment',
                DB::raw('(SELECT ' . dbPrefix() . 'creditnotes.total - (
                  (SELECT COALESCE(SUM(amount),0) FROM ' . dbPrefix() . 'credits WHERE ' . dbPrefix() . 'credits.credit_id=' . dbPrefix() . 'creditnotes.id)
                  +
                  (SELECT COALESCE(SUM(amount),0) FROM ' . dbPrefix() . 'creditnote_refunds WHERE ' . dbPrefix() . 'creditnote_refunds.credit_note_id=' . dbPrefix() . 'creditnotes.id)
                  )
                ) as remaining_amount'),
                DB::raw('(SELECT SUM(amount) FROM ' . dbPrefix() . 'creditnote_refunds WHERE credit_note_id=' . dbPrefix() . 'creditnotes.id) as refund_amount'),
                'status',
            ];

            $where = [];

            $creditNoteTaxesSelect = array_reverse($creditNoteTaxes);

            foreach ($creditNoteTaxesSelect as $key => $tax) {
                array_splice($select, 5, 0, DB::raw('(
                    SELECT CASE
                    WHEN discount_percent != 0 AND discount_type = "before_tax" THEN ROUND(SUM((qty*rate/100*' . dbPrefix() . 'item_tax.taxrate) - (qty*rate/100*' . dbPrefix() . 'item_tax.taxrate * discount_percent/100)),' . getDecimalPlaces() . ')
                    WHEN discount_total != 0 AND discount_type = "before_tax" THEN ROUND(SUM((qty*rate/100*' . dbPrefix() . 'item_tax.taxrate) - (qty*rate/100*' . dbPrefix() . 'item_tax.taxrate * (discount_total/subtotal*100) / 100)),' . getDecimalPlaces() . ')
                    ELSE ROUND(SUM(qty*rate/100*' . dbPrefix() . 'item_tax.taxrate),' . getDecimalPlaces() . ')
                    END
                    FROM ' . dbPrefix() . 'itemable
                    INNER JOIN ' . dbPrefix() . 'item_tax ON ' . dbPrefix() . 'item_tax.itemid=' . dbPrefix() . 'itemable.id
                    WHERE ' . dbPrefix() . 'itemable.rel_type="credit_note" AND taxname="' . $tax['taxname'] . '" AND taxrate="' . $tax['taxrate'] . '" AND ' . dbPrefix() . 'itemable.rel_id=' . dbPrefix() . 'creditnotes.id) as total_tax_single_' . $key'));
            }

            $customDateSelect = $this->getWhereReportPeriod();

            if ($customDateSelect != '') {
                array_push($where, $customDateSelect);
            }

            $byCurrency = Input::post('report_currency');

            if ($byCurrency) {
                $currency = $currenciesModel->find($byCurrency);
                array_push($where, 'AND currency=' . $this->db->escapeStr($byCurrency));
            } else {
                $currency = $currenciesModel->getBaseCurrency();
            }

            if (Input::post('credit_note_status')) {
                $statuses = Input::post('credit_note_status');
                $_statuses = [];

                if (is_array($statuses)) {
                    foreach ($statuses as $status) {
                        if ($status != '') {
                            array_push($_statuses, $this->db->escapeStr($status));
                        }
                    }
                }

                if (count($_statuses) > 0) {
                    array_push($where, 'AND status IN (' . implode(', ', $_statuses) . ')');
                }
            }

            $aColumns = $select;
            $sIndexColumn = 'id';
            $sTable = dbPrefix() . 'creditnotes';
            $join = [
                'LEFT JOIN ' . dbPrefix() . 'clients ON ' . dbPrefix() . 'clients.userid = ' . dbPrefix() . 'creditnotes.clientid',
            ];

            $result = DataTableHelper::init($aColumns, $sIndexColumn, $sTable, $join, $where, [
                'userid',
                'clientid',
                dbPrefix() . 'creditnotes.id',
                'discount_percent',
                'deleted_customer_name',
            ]);

            $output = $result['output'];
            $rResult = $result['rResult'];

            $footerData = [
                'total' => 0,
                'subtotal' => 0,
                'total_tax' => 0,
                'discount_total' => 0,
                'adjustment' => 0,
                'remaining_amount' => 0,
                'refund_amount' => 0,
            ];

            foreach ($creditNoteTaxes as $key => $tax) {
                $footerData['total_tax_single_' . $key] = 0;
            }

            foreach ($rResult as $aRow) {
                $row = [];

                $row[] = '<a href="' . admin_url('credit_notes/list_credit_notes/' . $aRow['id']) . '" target="_blank">' . format_credit_note_number($aRow['id']) . '</a>';

                $row[] = _d($aRow['date']);

                if (empty($aRow['deleted_customer_name'])) {
                    $row[] = '<a href="' . admin_url('clients/client/' . $aRow['clientid']) . '">' . $aRow['company'] . '</a>';
                } else {
                    $row[] = $aRow['deleted_customer_name'];
                }

                $row[] = $aRow['reference_no'];

                $row[] = app('money')->format($aRow['subtotal'], $currency->name);
                $footerData['subtotal'] += $aRow['subtotal'];

                $row[] = app('money')->format($aRow['total'], $currency->name);
                $footerData['total'] += $aRow['total'];

                $row[] = app('money')->format($aRow['total_tax'], $currency->name);
                $footerData['total_tax'] += $aRow['total_tax'];

                $t = $totalTaxesColumns - 1;
                $i = 0;

                foreach ($creditNoteTaxes as $tax) {
                    $row[] = app('money')->format(($aRow['total_tax_single_' . $t] == null ? 0 : $aRow['total_tax_single_' . $t]), $currency->name);
                    $footerData['total_tax_single_' . $i] += ($aRow['total_tax_single_' . $t] == null ? 0 : $aRow['total_tax_single_' . $t]);
                    $t--;
                    $i++;
                }

                $row[] = app('money')->format($aRow['discount_total'], $currency->name);
                $footerData['discount_total'] += $aRow['discount_total'];

                $row[] = app('money')->format($aRow['adjustment'], $currency->name);
                $footerData['adjustment'] += $aRow['adjustment'];

                $row[] = app('money')->format($aRow['remaining_amount'], $currency->name);
                $footerData['remaining_amount'] += $aRow['remaining_amount'];

                $row[] = app('money')->format($aRow['refund_amount'], $currency->name);
                $footerData['refund_amount'] += $aRow['refund_amount'];

                $row[] = format_credit_note_status($aRow['status']);

                $output['aaData'][] = $row;
            }

            foreach ($footerData as $key => $total) {
                $footerData[$key] = app('money')->format($total, $currency->name);
            }

            $output['sums'] = $footerData;

            return response()->json($output);
        }
    }
}
}
public function invoicesReport()
    {
        if (request()->ajax()) {
            $invoiceTaxes = $this->distinctTaxes('invoice');
            $totalTaxesColumns = count($invoiceTaxes);

            $currenciesModel = new CurrenciesModel();
            $invoicesModel = new InvoicesModel();

            $select = [
                'number',
                InvoiceHelper::getSqlSelectClientCompany(),
                DB::raw('YEAR(date) as year'),
                'date',
                'duedate',
                'subtotal',
                'total',
                'total_tax',
                'discount_total',
                'adjustment',
                DB::raw('(SELECT COALESCE(SUM(amount),0) FROM ' . dbPrefix() . 'credits WHERE ' . dbPrefix() . 'credits.invoice_id=' . dbPrefix() . 'invoices.id) as credits_applied'),
                DB::raw('(SELECT total - (SELECT COALESCE(SUM(amount),0) FROM ' . dbPrefix() . 'invoicepaymentrecords WHERE invoiceid = ' . dbPrefix() . 'invoices.id) - (SELECT COALESCE(SUM(amount),0) FROM ' . dbPrefix() . 'credits WHERE ' . dbPrefix() . 'credits.invoice_id=' . dbPrefix() . 'invoices.id)) as amount_open'),
                'status',
            ];

            $where = [
                'AND status != 5',
            ];

            $invoiceTaxesSelect = array_reverse($invoiceTaxes);

            foreach ($invoiceTaxesSelect as $key => $tax) {
                array_splice($select, 8, 0, DB::raw('(
                    SELECT CASE
                    WHEN discount_percent != 0 AND discount_type = "before_tax" THEN ROUND(SUM((qty*rate/100*' . dbPrefix() . 'item_tax.taxrate) - (qty*rate/100*' . dbPrefix() . 'item_tax.taxrate * discount_percent/100)),' . getDecimalPlaces() . ')
                    WHEN discount_total != 0 AND discount_type = "before_tax" THEN ROUND(SUM((qty*rate/100*' . dbPrefix() . 'item_tax.taxrate) - (qty*rate/100*' . dbPrefix() . 'item_tax.taxrate * (discount_total/subtotal*100) / 100)),' . getDecimalPlaces() . ')
                    ELSE ROUND(SUM(qty*rate/100*' . dbPrefix() . 'item_tax.taxrate),' . getDecimalPlaces() . ')
                    END
                    FROM ' . dbPrefix() . 'itemable
                    INNER JOIN ' . dbPrefix() . 'item_tax ON ' . dbPrefix() . 'item_tax.itemid=' . dbPrefix() . 'itemable.id
                    WHERE ' . dbPrefix() . 'itemable.rel_type="invoice" AND taxname="' . $tax['taxname'] . '" AND taxrate="' . $tax['taxrate'] . '" AND ' . dbPrefix() . 'itemable.rel_id=' . dbPrefix() . 'invoices.id) as total_tax_single_' . $key'));
            }

            $customDateSelect = $this->getWhereReportPeriod();
            if ($customDateSelect != '') {
                array_push($where, $customDateSelect);
            }

            if (Input::post('sale_agent_invoices')) {
                $agents = Input::post('sale_agent_invoices');
                $_agents = [];

                if (is_array($agents)) {
                    foreach ($agents as $agent) {
                        if ($agent != '') {
                            array_push($_agents, $this->db->escapeStr($agent));
                        }
                    }
                }

                if (count($_agents) > 0) {
                    array_push($where, 'AND sale_agent IN (' . implode(', ', $_agents) . ')');
                }
            }

            $byCurrency = Input::post('report_currency');
            $totalPaymentsColumnIndex = (12 + $totalTaxesColumns - 1);

            if ($byCurrency) {
                $_temp = substr($select[$totalPaymentsColumnIndex], 0, -2);
                $_temp .= ' AND currency =' . $byCurrency . ')) as amount_open';
                $select[$totalPaymentsColumnIndex] = $_temp;

                $currency = $currenciesModel->find($byCurrency);
                array_push($where, 'AND currency=' . $this->db->escapeStr($byCurrency));
            } else {
                $currency = $currenciesModel->getBaseCurrency();
                $select[$totalPaymentsColumnIndex] = $select[$totalPaymentsColumnIndex] .= ' as amount_open';
            }

            if (Input::post('invoice_status')) {
                $statuses = Input::post('invoice_status');
                $_statuses = [];

                if (is_array($statuses)) {
                    foreach ($statuses as $status) {
                        if ($status != '') {
                            array_push($_statuses, $this->db->escapeStr($status));
                        }
                    }
                }

                if (count($_statuses) > 0) {
                    array_push($where, 'AND status IN (' . implode(', ', $_statuses) . ')');
                }
            }

            $aColumns = $select;
            $sIndexColumn = 'id';
            $sTable = dbPrefix() . 'invoices';
            $join = [
                'LEFT JOIN ' . dbPrefix() . 'clients ON ' . dbPrefix() . 'clients.userid = ' . dbPrefix() . 'invoices.clientid',
            ];

            $result = DataTableHelper::init($aColumns, $sIndexColumn, $sTable, $join, $where, [
                'userid',
                'clientid',
                dbPrefix() . 'invoices.id',
                'discount_percent',
                'deleted_customer_name',
            ]);

            $output = $result['output'];
            $rResult = $result['rResult'];

            $footerData = [
                'total' => 0,
                'subtotal' => 0,
                'total_tax' => 0,
                'discount_total' => 0,
                'adjustment' => 0,
                'applied_credits' => 0,
                'amount_open' => 0,
            ];

            foreach ($invoiceTaxes as $key => $tax) {
                $footerData['total_tax_single_' . $key] = 0;
            }

            foreach ($rResult as $aRow) {
                $row = [];

                $row[] = '<a href="' . admin_url('invoices/list_invoices/' . $aRow['id']) . '" target="_blank">' . format_invoice_number($aRow['id']) . '</a>';

                if (empty($aRow['deleted_customer_name'])) {
                    $row[] = '<a href="' . admin_url('clients/client/' . $aRow['userid']) . '" target="_blank">' . $aRow['company'] . '</a>';
                } else {
                    $row[] = $aRow['deleted_customer_name'];
                }

                $row[] = $aRow['year'];

                $row[] = _d($aRow['date']);

                $row[] = _d($aRow['duedate']);

                $row[] = app('money')->format($aRow['subtotal'], $currency->name);
                $footerData['subtotal'] += $aRow['subtotal'];

                $row[] = app('money')->format($aRow['total'], $currency->name);
                $footerData['total'] += $aRow['total'];

                $row[] = app('money')->format($aRow['total_tax'], $currency->name);
                $footerData['total_tax'] += $aRow['total_tax'];

                $t = $totalTaxesColumns - 1;
                $i = 0;

                foreach ($invoiceTaxes as $tax) {
                    $row[] = app('money')->format(($aRow['total_tax_single_' . $t] == null ? 0 : $aRow['total_tax_single_' . $t]), $currency->name);
                    $footerData['total_tax_single_' . $i] += ($aRow['total_tax_single_' . $t] == null ? 0 : $aRow['total_tax_single_' . $t]);
                    $t--;
                    $i++;
                }

                $row[] = app('money')->format($aRow['discount_total'], $currency->name);
                $footerData['discount_total'] += $aRow['discount_total'];

                $row[] = app('money')->format($aRow['adjustment'], $currency->name);
                $footerData['adjustment'] += $aRow['adjustment'];

                $row[] = app('money')->format($aRow['credits_applied'], $currency->name);
                $footerData['applied_credits'] += $aRow['credits_applied'];

                $amountOpen = $aRow['amount_open'];
                $row[] = app('money')->format($amountOpen, $currency->name);
                $footerData['amount_open'] += $amountOpen;

                $row[] = format_invoice_status($aRow['status']);

                $output['aaData'][] = $row;
            }

            foreach ($footerData as $key => $total) {
                $footerData[$key] = app('money')->format($total, $currency->name);
            }

            $output['sums'] = $footerData;
            return response()->json($output);
        }
    }
    public function expenses($type = 'simple_report')
    {
        $currenciesModel = new CurrenciesModel();
        $expensesModel = new ExpensesModel();
        $paymentModesModel = new PaymentModesModel();

        $data['base_currency'] = $currenciesModel->getBaseCurrency();
        $data['currencies'] = $currenciesModel->get();

        $data['title'] = trans('expenses_report');

        if ($type != 'simple_report') {
            $data['categories'] = $expensesModel->getCategory();
            $data['years'] = $expensesModel->getExpensesYears();

            $data['payment_modes'] = $paymentModesModel->get('', [], true);

            if (request()->ajax()) {
                $aColumns = [
                    'expenses_categories.name as category_name',
                    'amount',
                    'expense_name',
                    'tax',
                    'tax2',
                    'taxes.taxrate as tax1_taxrate',
                    'amount as amount_with_tax',
                    'billable',
                    'date',
                    ExpenseHelper::getSqlSelectClientCompany(),
                    'invoiceid',
                    'reference_no',
                    'paymentmode',
                ];

                $join = [
                    'LEFT JOIN ' . dbPrefix() . 'clients ON ' . dbPrefix() . 'clients.userid = ' . dbPrefix() . 'expenses.clientid',
                    'LEFT JOIN ' . dbPrefix() . 'expenses_categories ON ' . dbPrefix() . 'expenses_categories.id = ' . dbPrefix() . 'expenses.category',
                    'LEFT JOIN ' . dbPrefix() . 'taxes ON ' . dbPrefix() . 'taxes.id = ' . dbPrefix() . 'expenses.tax',
                    'LEFT JOIN ' . dbPrefix() . 'taxes as taxes_2 ON taxes_2.id = ' . dbPrefix() . 'expenses.tax2',
                ];

                $where = [];
                $filter = [];

                include_once(resource_path('views/admin/tables/includes/expenses_filter.php'));

                if (count($filter) > 0) {
                    array_push($where, 'AND (' . prepareDtFilter($filter) . ')');
                }

                $byCurrency = Input::post('currency');

                if ($byCurrency) {
                    $currency = $currenciesModel->get($byCurrency);
                    array_push($where, 'AND currency=' . $this->db->escapeStr($byCurrency));
                } else {
                    $currency = $currenciesModel->getBaseCurrency();
                }

                $sIndexColumn = 'id';
                $sTable = dbPrefix() . 'expenses';

                $result = DataTableHelper::init($aColumns, $sIndexColumn, $sTable, $join, $where, [
                    'expenses_categories.name as category_name',
                    'expenses.id',
                    'expenses.clientid',
                    'currency',
                    'taxes.name as tax1_name',
                    'taxes.taxrate as tax1_taxrate',
                    'taxes_2.name as tax2_name',
                    'taxes_2.taxrate as tax2_taxrate',
                ]);

                $output = $result['output'];
                $rResult = $result['rResult'];

                $footerData = [
                    'tax_1' => 0,
                    'tax_2' => 0,
                    'amount' => 0,
                    'total_tax' => 0,
                    'amount_with_tax' => 0,
                ];

                foreach ($rResult as $aRow) {
                    $row = [];

                    for ($i = 0; $i < count($aColumns); $i++) {
                        if (strpos($aColumns[$i], 'as') !== false && !isset($aRow[$aColumns[$i]])) {
                            $_data = $aRow[strafter($aColumns[$i], 'as ')];
                        } else {
                            $_data = $aRow[$aColumns[$i]];
                        }

                        if ($aColumns[$i] == 'expenses_categories.name') {
                            $_data = '<a href="' . admin_url('expenses/list_expenses/' . $aRow['id']) . '" target="_blank">' . $aRow['category_name'] . '</a>';
                        } elseif ($aColumns[$i] == 'expense_name') {
                            $_data = '<a href="' . admin_url('expenses/list_expenses/' . $aRow['id']) . '" target="_blank">' . $aRow['expense_name'] . '</a>';
                        } elseif ($aColumns[$i] == 'amount' || $i == 6) {
                            $total = $_data;

                            if ($i != 6) {
                                $footerData['amount'] += $total;
                            } else {
                                if ($aRow['tax'] != 0 && $i == 6) {
                                    $total += ($total / 100 * $aRow['tax1_taxrate']);
                                }

                                if ($aRow['tax2'] != 0 && $i == 6) {
                                    $total += ($aRow['amount'] / 100 * $aRow['tax2_taxrate']);
                                }

                                $footerData['amount_with_tax'] += $total;
                            }

                            $_data = app_format_money($total, $currency->name);
                        } elseif ($i == 9) {
                            $_data = '<a href="' . admin_url('clients/client/' . $aRow['clientid']) . '">' . $aRow['company'] . '</a>';
                        } elseif ($aColumns[$i] == 'paymentmode') {
                            $_data = '';

                            if ($aRow['paymentmode'] != '0' && !empty($aRow['paymentmode'])) {
                                $paymentMode = $paymentModesModel->get($aRow['paymentmode'], [], false, true);

                                if ($paymentMode) {
                                    $_data = $paymentMode->name;
                                }
                            }
                        } elseif ($aColumns[$i] == 'date') {
                            $_data = _d($_data);
                        } elseif ($aColumns[$i] == 'tax') {
                            if ($aRow['tax'] != 0) {
                                $_data = $aRow['tax1_name'] . ' - ' . app_format_number($aRow['tax1_taxrate']) . '%';
                            } else {
                                $_data = '';
                            }
                        } elseif ($aColumns[$i] == 'tax2') {
                            if ($aRow['tax2'] != 0) {
                                $_data = $aRow['tax2_name'] . ' - ' . app_format_number($aRow['tax2_taxrate']) . '%';
                            } else {
                                $_data = '';
                            }
                        } elseif ($i == 5) {
                            if ($aRow['tax'] != 0 || $aRow['tax2'] != 0) {
                                if ($aRow['tax'] != 0) {
                                    $total = ($total / 100 * $aRow['tax1_taxrate']);
                                    $footerData['tax_1'] += $total;
                                }

                                if ($aRow['tax2'] != 0) {
                                    $totalTax2 = ($aRow['amount'] / 100 * $aRow['tax2_taxrate']);
                                    $total += $totalTax2;
                                    $footerData['tax_2'] += $totalTax2;
                                }

                                $_data = app_format_money($total, $currency->name);
                                $footerData['total_tax'] += $total;
                            } else {
                                $_data = app_format_number(0);
                            }
                        } elseif ($aColumns[$i] == 'billable') {
                            if ($aRow['billable'] == 1) {
                                $_data = trans('expenses_list_billable');
                            } else {
                                $_data = trans('expense_not_billable');
                            }
                        } elseif ($aColumns[$i] == 'invoiceid') {
                            if ($_data) {
                                $_data = '<a href="' . admin_url('invoices/list_invoices/' . $_data) . '">' . format_invoice_number($_data) . '</a>';
                            } else {
                                $_data = '';
                            }
                        }

                        $row[] = $_data;
                    }

                    $output['aaData'][] = $row;
                }

                foreach ($footerData as $key => $total) {
                    $footerData[$key] = app_format_money($total, $currency->name);
                }

                $output['sums'] = $footerData;

                return response()->json($output);
            }

            return view('admin/reports/expenses_detailed', $data);
        } else {
            if (!request()->has('year')) {
                $data['current_year'] = date('Y');
            } else {
                $data['current_year'] = request()->input('year');
            }

            $data['export_not_supported'] = ($this->agent->browser() == 'Internet Explorer' || $this->agent->browser() == 'Spartan');

            $data['chart_not_billable'] = json_encode($this->reports_model->getStatsChartData(trans('not_billable_expenses_by_categories'), [
                'billable' => 0,
            ], [
                'backgroundColor' => 'rgba(252,45,66,0.4)',
                'borderColor' => '#fc2d42',
            ], $data['current_year']));

            $data['chart_billable'] = json_encode($this->reports_model->getStatsChartData(trans('billable_expenses_by_categories'), [
                'billable' => 1,
            ], [
                'backgroundColor' => 'rgba(37,155,35,0.2)',
                'borderColor' => '#84c529',
            ], $data['current_year']));

            $data['expense_years'] = $expensesModel->getExpensesYears();

            if (count($data['expense_years']) > 0) {
                if (!in_array_multidimensional($data['expense_years'], 'year', date('Y'))) {
                    array_unshift($data['expense_years'], ['year' => date('Y')]);
                }
            }

            $data['categories'] = $expensesModel->getCategory();

            return view('admin/reports/expenses', $data);
        }
    }
    public function expensesVsIncome($year = '')
    {
        $_expensesYears = [];
        $_years = [];

        $expensesModel = new ExpensesModel();
        $reportsModel = new ReportsModel();

        $expensesYears = $expensesModel->getExpensesYears();
        $paymentsYears = $reportsModel->getDistinctPaymentsYears();

        foreach ($expensesYears as $y) {
            array_push($_years, $y['year']);
        }

        foreach ($paymentsYears as $y) {
            array_push($_years, $y['year']);
        }

        $_years = array_map('unserialize', array_unique(array_map('serialize', $_years)));

        if (!in_array(date('Y'), $_years)) {
            $_years[] = date('Y');
        }

        rsort($_years, SORT_NUMERIC);

        $data['report_year'] = $year == '' ? date('Y') : $year;
        $data['years'] = $_years;
        $data['chartExpensesVsIncomeValues'] = json_encode($reportsModel->getExpensesVsIncomeReport($year));
        $data['base_currency'] = getBaseCurrency();
        $data['title'] = trans('als_expenses_vs_income');

        return view('admin/reports/expenses_vs_income', $data);
    }

    /* Total income report / ajax chart*/
    public function totalIncomeReport()
    {
        return response()->json((new ReportsModel())->totalIncomeReport());
    }

    public function reportByPaymentModes()
    {
        return response()->json((new ReportsModel())->reportByPaymentModes());
    }

    public function reportByCustomerGroups()
    {
        return response()->json((new ReportsModel())->reportByCustomerGroups());
    }

    /* Leads conversion monthly report / ajax chart*/
    public function leadsMonthlyReport($month)
    {
        return response()->json((new ReportsModel())->leadsMonthlyReport($month));
    }

    private function distinctTaxes($relType)
    {
        return DB::select('SELECT DISTINCT taxname, taxrate FROM ' . dbPrefix() . "item_tax WHERE rel_type='" . $relType . "' ORDER BY taxname ASC");
    }
}
