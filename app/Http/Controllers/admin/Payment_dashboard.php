<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Payment_dashboard extends AdminController
{
    /* List all candidates */
    public function index()
    {
        $payment_dashboard_results = $this->getCall();
        while (empty($payment_dashboard_results)) {
            $payment_dashboard_results = $this->getCall();
        }
        $data['payment_dashboard_results'] = json_decode($payment_dashboard_results);
        $this->load->view('admin/payment_dashboard/manage', $data);
    }

    public function getCall()
    {
        try {
            if (isset($_COOKIE['_callvu_Flex360payment-' . get_staff_user_id()])) {
                return $_COOKIE['_callvu_Flex360payment-' . get_staff_user_id()];
            } else {

                $endpoint_url = get_option('CALL_VU_VERIFIED_CALL_AND_PAYMENTS');
                $decrypted_payload = ['startDate' => '2022-12-01', 'FLEX_360_ID' => 4502];
                $csrf_key = $this->security->get_csrf_token_name();
                $csrf_value = $this->security->get_csrf_hash();

                $this->loadEncryptionScripts();

                echo '
                    <script>
                    let payload = ' . json_encode($decrypted_payload) . ';
                    let endpoint = "' . $endpoint_url . '";
                    let publicKey = "' . $this->getPublicKey() . '";
                    const resultArr = encryptData(payload, publicKey);
                    const resultStr = resultArr.join(",");
                    const xhr = new XMLHttpRequest();
                    xhr.open("POST", endpoint, true);
                    xhr.onreadystatechange = function() {
                        if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {
                            const responseText = xhr.responseText;
                            console.log("@@@@", responseText);
                            document.cookie = "_callvu_Flex360payment-' . get_staff_user_id() . ' = " + responseText ;
                        } else {
                            return "fail";
                        }                
                    };
                    xhr.send(resultStr);
                </script>';
            }
        } catch (Exception $ex) {
            print_r($ex);
            die;
        }
    }

    public function get_alert_intake()
    {
        try {
            $post_data = $this->input->post();
            if (!empty($post_data)) {
                $data = json_decode($post_data['response']);
                if (!empty($data)) {
                    $GLOBALS['callvu_payment_result'] = $data->data;
                    echo json_encode($GLOBALS['callvu_payment_result']);
                }
            }
        } catch (Exception $ex) {
            print_r($ex);
            die;
        }
    }

    private function loadEncryptionScripts()
    {
        echo '
            <script src="' . base_url('assets/encryption/jsencrypt.min.js') . '"></script> 
            <script src="' . base_url('assets/encryption/encryptionUtils.js') . '"></script> 
            <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js" type="text/javascript"></script>';
    }

    private function getPublicKey()
    {
        $publicKey = "-----BEGIN PUBLIC KEY-----";
        $publicKey .= "MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAjKGEVHDAQaYGWTRLaMqnKpoqTQzE3Noa5+tcsQrCcHMafOmg2n8IocTxSb6AHbcvq6y1QqXt8CFyF5lB//iTSRcINwGOE5QyzXCmhPg/zvMqFi8VVTNZy2R/97/87Ylahs3hmxxK27z5WW+3xY1AQPD9jSpQAXYSDUsf3zqzMKmNvMlzbkv/FjyoRASab4XU/iA8kTH2jTe/4vI/FZSID+QLlGs5SpbNidekMo6MgHwipCLYkMn2h8qcfB3BvTGuy1TSM3QYII8vtdD5vSMrNM6xDeHKG/uAXt0UmtwaRVTY3DsJFMXFlmsCGyWIKUmKf9YZVtqPYo+6iJL6f88XX0CIWgyYvZsyEqLCDQqENh4g9ttmHLouv1rjpFeyIvW/ereRdrVByqQMdxz5RpDhPqcg18aB8l8oO5G1Yp5iVS9UNJqw59ZDWCyWCuqU1IVBDeHLypUj8/z8urZy9eUVPZk2ZMrOXSho5qOu+4QjjfqfQ/tM6P+dunMU67+PUvzqCNav1w5uejB3JUe0i6RAeFcMZe3/QSJtsQDnvDLi1aL+dLkIMH19qvRb30bxqI3x/yGnDaysKoL1P2VtiJVENs828q3TStHdRjE0j4KzNb2E8Zf374l9mSqglAsbDfrA4QEtsGNmJmBiKmdDih2timOoO/V+92lqrAKcKUQHfdMCAwEAAQ==";
        $publicKey .= "-----END PUBLIC KEY-----";

        return $publicKey;
    }
}
