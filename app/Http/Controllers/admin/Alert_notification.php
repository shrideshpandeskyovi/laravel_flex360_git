namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AlertNotification;

class AlertNotificationController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->alertNotificationModel = new AlertNotification();
    }

    public function index()
    {
        $data['alert_Notification'] = $this->alertNotificationModel->getCandidates();
        $data['title'] = __('FLEX360');

        return view('admin.alert_notification', $data);
    }

    public function test()
    {
        return phpinfo();
    }
}
