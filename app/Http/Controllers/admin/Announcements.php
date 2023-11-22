<?php
namespace App\Http\Controllers;
// Include any necessary libraries or configurations here
use App\Announcement;  //add correct link or address
use Illuminate\Http\Request;
class Announcements
{
    public function __construct(Announcement $announcement)
    {
      $this->announcement = $announcement;
    }

    public function index()
  {
    $announcements = Announcement::all();

    return view('announcements.index', compact('announcements'));
  }

  public function announcement($id = '') 
  {
    if($id == '') {
      $announcement = new Announcement; 
      $title = 'Create Announcement';
    } else {
      $announcement = Announcement::findOrFail($id);
      $title = 'Edit Announcement';
    }

    if(request()->isMethod('post')) {
      $data = request()->validate([
        'message' => 'required'
      ]);

      if($id == '') {
        $announcement = Announcement::create($data);
      } else {
        $announcement->update($data);
      }

      return redirect('/announcements');
    }

    $roles = Role::all();
    $staff = Staff::all();

    return view('announcements.announcement', compact('announcement','roles','staff','title'));
  }

  public function show($id)
  {
    $this->authorize('view', Announcement::class);
    
    $announcement = Announcement::findOrFail($id);
  
    $recentAnnouncements = Announcement::where('id', '!=', $id)
                                ->latest()
                                ->take(4)
                                ->get();
  
    return view('announcements.show', [
      'announcement' => $announcement, 
      'recentAnnouncements' => $recentAnnouncements
    ]);
  }

    public function destroy($id)
    {
      if(!$id) {
        return redirect()->route('announcements.index');
      }
    
      $this->authorize('delete', Announcement::class);
    
      $announcement = Announcement::findOrFail($id);
    
      if ($announcement->delete()) {
        // Success
        session()->flash('success', 'Announcement deleted');
        return redirect()->route('announcements.index');
    
      } else {
        // Error
        session()->flash('error', 'Error deleting announcement');
        return back();
      }
    }

    public function show($id)
    {
      // Authorization logic
    
      $announcement = Announcement::findOrFail($id);
    
      $recentAnnouncements = Announcement::where('id', '!=', $id)
                              ->latest()
                              ->take(4)
                              ->get();
    
      return view('announcements.show', [
        'announcement' => $announcement,
        'recentAnnouncements' => $recentAnnouncements
      ]);
    
    }
    
    public function destroy($id)
    {
       // Authorization
    
       $announcement = Announcement::findOrFail($id);
    
       if($announcement->delete()) {
         // Success message
       } else {
         // Error message
       }
    
       return redirect('/announcements');
    }
    
    
    public function send(Request $request)
    {
      $announcementId = $request->post('announcement_id');
    
      $announcement = Announcement::findOrFail($announcementId);
    
      $staffIds = // get staff IDs based on announcement
    
      // Prepare notification data
    
      $staffIds->each(function ($staff) use ($notification) {
        Notification::send($staff, $notification);
      });
    
    }

    // Add the necessary methods and logic for your specific application
}

// Instantiate the Announcements class
$announcementsController = new Announcements();

// Handle HTTP requests and route them to the appropriate controller methods
// Implement routing logic, form handling, and any other necessary logic

// Output HTML and responses based on the results of the controller methods

?>
