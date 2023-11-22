<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Newsfeed extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('newsfeed_model');
        $this->load->model('departments_model');
    }

    /* Init newsfeed in homepage */
    public function loadNewsfeed()
    {
        $__postId = '';
        if ($this->input->post('postid')) {
            $__postId = $this->input->post('postid');
        }

        $posts = $this->newsfeed_model->loadNewsfeed($this->input->post('page'), $__postId);
        $response = '';

        $staffDepartments = $this->departments_model->getStaffDepartments(get_staff_user_id(), true);

        // Add pinned posts only when refreshing the entire feed
        if (!$this->input->post('postid') && ($this->input->post('page') == 0)) {
            $pinnedPosts = $this->newsfeed_model->getPinnedPosts();
            $posts = array_merge($pinnedPosts, $posts);
        }

        foreach ($posts as $post) {
            $visibleDepartments = '';
            $notVisible = false;
            $visibility = explode(':', $post['visibility']);

            if ($visibility[0] != 'all') {
                foreach ($visibility as $visible) {
                    if (!in_array($visible, $staffDepartments)) {
                        if (!is_admin() && $post['creator'] != get_staff_user_id()) {
                            $notVisible = true;
                        }
                    }
                    $visibleDepartments .= $this->departments_model->get($visible)->name . ', ';
                }
            }

            if ($notVisible) {
                continue;
            }

            $pinnedClass = ($post['pinned'] == 1) ? ' pinned' : '';

            $response .= '<div class="panel_s newsfeed_post' . $pinnedClass . '" data-main-postid="' . $post['postid'] . '">';
            $response .= '<div class="panel-body post-content">';
            $response .= '<div class="media">';
            $response .= '<div class="media-left">';
            $response .= '<a href="' . admin_url('profile/' . $post['creator']) . '">' . staff_profile_image($post['creator'], [
                'staff-profile-image-small',
                'no-radius',
            ]) . '</a>';
            $response .= '</div>';
            $response .= '<div class="media-body">';
            $response .= '<p class="media-heading no-mbot"><a href="' . admin_url('profile/' . $post['creator']) . '">' . get_staff_full_name($post['creator']) . '</a></p>';
            $response .= '<small class="post-time-ago">' . time_ago($post['datecreated']) . '</small>';

            if ($post['creator'] == get_staff_user_id() || is_admin()) {
                $response .= '<div class="dropdown pull-right btn-post-options-wrapper">';
                $response .= '<button class="btn btn-default dropdown-toggle btn-post-options btn-icon" data-toggle="dropdown" aria-haspopup="true" aria-expanded="true"><i class="fa fa-angle-down"></i></button>';
                $response .= '<ul class="dropdown-menu">';
                
                if ($post['pinned'] == 0) {
                    $response .= '<li><a href="#" onclick="pin_post(' . $post['postid'] . '); return false;">' . _l('newsfeed_pin_post') . '</a></li>';
                } else {
                    $response .= '<li><a href="#" onclick="unpin_post(' . $post['postid'] . '); return false;">' . _l('newsfeed_unpin_post') . '</a></li>';
                }

                $response .= '<li><a href="#" onclick="delete_post(' . $post['postid'] . '); return false;">' . _l('newsfeed_delete_post') . '</a></li>';
                $response .= '</ul>';
                $response .= '</div>';
            }

            $response .= '<small class="text-muted">' . _l('newsfeed_published_post') . ': ' . _dt($post['datecreated']) . '</small>';
            $response .= '</div>';
            $response .= '</div>'; // media end
            $response .= '<div class="post-content mtop20 display-block">';
            
            if (!empty($visibleDepartments)) {
                $visibleDepartments = substr($visibleDepartments, 0, -2);
                $response .= '<i class="fa-regular fa-circle-question" data-toggle="tooltip" data-title="' . _l('newsfeed_newsfeed_post_only_visible_to_departments', $visibleDepartments) . '"></i> ';
            }

            $response .= check_for_links($post['content']);
            $response .= '<div class="clearfix mbot10"></div>';

            $imageAttachments = $this->newsfeed_model->getPostAttachments($post['postid'], true);
            $totalImageAttachments = count($imageAttachments);
            $nonImageAttachments = $this->newsfeed_model->getPostAttachments($post['postid']);

            if ($totalImageAttachments > 0) {
                $response .= '<hr />';
                $response .= '<ul class="list-unstyled">';
                $a = 0;

                foreach ($imageAttachments as $attachment) {
                    $_wrapperAdditionalClass = ($totalImageAttachments <= 3) ? 'post-image-wrapper-' . $totalImageAttachments . ' ' : ' ';
                    $response .= '<div class="post-image-wrapper ' . $_wrapperAdditionalClass . 'mbot10">';
                    $response .= '<a href="' . base_url('uploads/newsfeed/' . $post['postid'] . '/' . $attachment['file_name']) . '" data-lightbox="post-' . $post['postid'] . '"><img src="' . base_url('uploads/newsfeed/' . $post['postid'] . '/' . $attachment['file_name']) . '" class="img img-responsive"></a>';
                    $response .= '</div>';

                    if ($a == 5) {
                        $totalLeft = $totalImageAttachments - 6;

                        if ($totalLeft > 0) {
                            $nextImageAttachmentUrl = base_url('uploads/newsfeed/' . $post['postid'] . '/' . $imageAttachments[$a + 1]['file_name']);
                            $response .= '<div class="clearfix"></div><a href="' . $nextImageAttachmentUrl . '" class="pull-right" data-lightbox="post-' . $post['postid'] . '">+' . $totalLeft . ' more</a>';
                            break;
                        }
                    }

                    $a++;
                }

                // Hidden images for +X left lightbox
                for ($i = $a + 2; $i < $totalImageAttachments; $i++) {
                    $response .= '<a href="' . base_url('uploads/newsfeed/' . $post['postid'] . '/' . $imageAttachments[$i]['file_name']) . '" data-lightbox="post-' . $post['postid'] . '"></a>';
                }

                $response .= '</ul>';
            }

            if (count($nonImageAttachments) > 0) {
                if ($totalImageAttachments == 0) {
                    $response .= '<hr />';
                }

                $response .= '<div class="clearfix"></div>';
                $response .= '<ul class="list-unstyled">';

                foreach ($nonImageAttachments as $attachment) {
                    $response .= '<li><i class="' . get_mime_class($attachment['filetype']) . '"></i> <a href="' . site_url('download/file/newsfeed/' . $attachment['id']) . '">' . $attachment['file_name'] . '</a></li>';
                }

                $response .= '<ul>';
            }

            $response .= '</div>';
            $response .= '</div>'; // panel body end
            $response .= '<div class="post_likes_wrapper" data-likes-postid="' . $post['postid'] . '">';
            $response .= $this->initPostLikes($post['postid']);
            $response .= '</div>';
            // Comments
            $response .= '<div class="post_comments_wrapper" data-comments-postid="' . $post['postid'] . '">';
            $response .= $this->initPostComments($post['postid']);
            $response .= '</div>';
            $response .= '<div class="panel-footer user-comment">';
            $response .= '<div class="pull-left comment-image">';
            $response .= '<a href="' . admin_url('profile/' . $post['creator']) . '">' . staff_profile_image(get_staff_user_id(), [
                'staff-profile-image-small',
                'no-radius',
            ]) . '</a>';
            $response .= '</div>'; // end comment-image
            $response .= '<div class="media-body comment-input">';
            $response .= '<input type="text" class="form-control input-sm" placeholder="' . _l('comment_this_post_placeholder') . '" data-postid="' . $post['postid'] . '">';
            $response .= '</div>'; // end comment-input
            $response .= '</div>'; // end user-comment
            $response .= '</div>'; // panel end
        }

        echo $response;
    }
    public function initPostLikes($id)
{
    $likesHtml = '<div class="panel-footer user-post-like">';

    if (!$this->newsfeed_model->userLikedPost($id)) {
        $likesHtml .= '<button type="button" class="btn btn-default btn-icon" onclick="like_post(' . $id . ')"> <i class="fa fa-heart"></i></button>';
    } else {
        $likesHtml .= '<button type="button" class="btn btn-danger btn-icon" onclick="unlike_post(' . $id . ')"> <i class="fa-regular fa-heart"></i></button>';
    }

    $likesHtml .= '</div>';

    $totalPostLikes = total_rows(db_prefix() . 'newsfeed_post_likes', ['postid' => $id]);

    if ($totalPostLikes > 0) {
        $likesHtml .= '<div class="panel-footer post-likes">';
        $totalLikes = $this->newsfeed_model->getPostLikes($id);
        $totalPages = ceil($totalLikes / $this->newsfeed_model->postLikesLimit);
        $likesModal = '<a href="#" onclick="return false;" data-toggle="modal" data-target="#modal_post_likes" data-postid="' . $id . '" data-total-pages="' . $totalPages . '">';

        if ($this->newsfeed_model->userLikedPost($id) && $totalPostLikes == 1) {
            $likesHtml .= _l('newsfeed_you_like_this');
        } elseif (($this->newsfeed_model->userLikedPost($id) && $totalPostLikes > 1) || ($this->newsfeed_model->userLikedPost($id) && $totalPostLikes >= 2)) {
            if ($totalLikes == 1) {
                $likesHtml .= _l('newsfeed_you_and') . ' ' . $totalLikes[0]['name'] . ' ' . _l('newsfeed_like_this');
            } elseif ($totalLikes == 2) {
                $likesHtml .= _l('newsfeed_you') . ', ' . $totalLikes[0]['name'] . ' and ' . $totalLikes[1]['name'] . _l('newsfeed_like_this');
            } else {
                $likesHtml .= 'You, ' . $totalLikes[0]['name'] . ', ' . $totalLikes[1]['name'] . ' and ' . $likesModal . ' ' . ($totalLikes - 2) . ' ' . _l('newsfeed_one_other') . '</a> ' . _l('newsfeed_like_this');
            }
        } else {
            $i = 1;
            foreach ($totalLikes as $like) {
                if ($i > 3) {
                    $totalLeft = ($totalLikes - 3);
                    if ($totalLeft != 0) {
                        $likesHtml = substr($likesHtml, 0, -2);
                        $likesHtml .= $likesModal . ' ' . _l('newsfeed_and') . ' ' . $totalLeft . ' </a>' . _l('newsfeed_like_this');
                    } else {
                        $likesHtml = substr($likesHtml, 0, -2) . ' ' . _l('newsfeed_like_this');
                    }

                    break;
                }
                $likesHtml .= $like['name'] . ', ';
                $i++;
            }
            if ($i < 4) {
                $likesHtml = substr($likesHtml, 0, -2);
                $likesHtml .= ' ' . _l('newsfeed_like_this');
            }
        }
        $likesHtml .= '</div>'; // panel footer
    }

    if ($this->input->is_ajax_request() && $this->input->get('refresh_post_likes')) {
        echo $likesHtml;
    } else {
        return $likesHtml;
    }
}

/* Init post comments */
public function initPostComments($id)
{
    $commentsHtml = '';
    $totalComments = total_rows(db_prefix() . 'newsfeed_post_comments', ['postid' => $id]);

    if ($totalComments > 0) {
        $page = $this->input->post('page');

        if (!$this->input->post('page')) {
            $commentsHtml .= '<div class="panel-footer post-comment">';
        }

        $comments = $this->newsfeed_model->getPostComments($id, $page);
        $totalCommentPages = ceil($totalComments / $this->newsfeed_model->postCommentsLimit);

        foreach ($comments as $comment) {
            $commentsHtml .= $this->commentSingle($comment);
        }

        if ($totalComments > $this->newsfeed_model->postCommentsLimit && !$this->input->post('page')) {
            $commentsHtml .= '<a href="#" onclick="load_more_comments(this); return false" class="mtop10 load-more-comments display-block" data-postid="' . $id . '" data-total-pages="' . $totalCommentPages . '"><input type="hidden" name="page" value="1">' . _l('newsfeed_show_more_comments') . '</a>';
        }

        if (!$this->input->post('page')) {
            $commentsHtml .= '</div>'; // end comments footer
        }
    }

    if (($this->input->is_ajax_request() && $this->input->get('refresh_post_comments')) || ($this->input->is_ajax_request() && $this->input->post('page'))) {
        echo $commentsHtml;
    } else {
        return $commentsHtml;
    }
}

public function commentSingle($comment)
{
    $commentHtml = '<div class="comment" data-commentid="' . $comment['id'] . '">';
    $commentHtml .= '<div class="pull-left comment-image">';
    $commentHtml .= '<a href="' . admin_url('profile/' . $comment['userid']) . '">' . staff_profile_image($comment['userid'], [
        'staff-profile-image-small',
        'no-radius',
    ]) . '</a>';
    $commentHtml .= '</div>'; // end comment-image

    if ($comment['userid'] == get_staff_user_id() || is_admin()) {
        $commentHtml .= '<span class="pull-right"><a href="#" class="remove-post-comment" onclick="remove_post_comment(' . $comment['id'] . ',' . $comment['postid'] . '); return false;"><i class="fa fa-remove"></i></span></a>';
    }

    $commentHtml .= '<div class="media-body">';
    $commentHtml .= '<p class="no-margin comment-content"><a href="' . admin_url('profile/' . $comment['userid']) . '">' . get_staff_full_name($comment['userid']) . '</a> ' . check_for_links($comment['content']) . '</p>';
    $totalCommentLikes = total_rows(db_prefix() . 'newsfeed_comment_likes', ['commentid' => $comment['id'], 'postid' => $comment['postid']]);
    $totalPages = ceil($totalCommentLikes / $this->newsfeed_model->postCommentsLimit);
    $likesModal = '<a href="#" onclick="return false;" data-toggle="modal" data-target="#modal_post_comment_likes" data-commentid="' . $comment['id'] . '" data-total-pages="' . $totalPages . '">';
    $commentLikesHtml = '';

    if ($totalCommentLikes > 0) {
        $commentLikesHtml = ' - ' . $likesModal . $totalCommentLikes . ' <i class="fa fa-thumbs-o-up"></i></a>';
    } else {
        $commentLikesHtml .= '</a>';
    }

    if (!$this->newsfeed_model->userLikedComment($comment['id'])) {
        $commentHtml .= '<p class="no-margin"><a href="#" onclick="like_comment(' . $comment['id'] . ',' . $comment['postid'] . '); return false;"><small>' . _l('newsfeed_like_this_saying') . ' ' . $commentLikesHtml . ' - ' . _dt($comment['dateadded']) . '</small></p>';
    } else {
        $commentHtml .= '<p class="no-margin"><a href="#" onclick="unlike_comment(' . $comment['id'] . ',' . $comment['postid'] . '); return false;"><small>' . _l('newsfeed_unlike_this_saying') . ' ' . $commentLikesHtml . ' - ' . _dt($comment['dateadded']) . '</small></p>';
    }

    $commentHtml .= '</div>';
    $commentHtml .= '</div>';
    $commentHtml .= '<div class="clearfix"></div>';

    return $commentHtml;
}
public function getData()
{
    $this->load->model('departments_model');
    $data['departments'] = $this->departments_model->get();
    $this->load->view('admin/includes/modals/newsfeed_form', $data);
}

/* Likes modal to see all post likes */
public function loadLikesModal()
{
    if ($this->input->post()) {
        $likes = $this->newsfeed_model->loadLikesModal($this->input->post('page'), $this->input->post('postid'));
        $likesHtml = '';

        foreach ($likes as $like) {
            $likesHtml .= '<div class="pull-left modal_like_area"><a href="' . admin_url('profile/' . $like['userid']) . '" target="_blank">' . staff_profile_image($like['userid'], [
                'staff-profile-image-small',
                'no-radius',
                'pull-left',
            ]) . '</a>
            <div class="media-body">
             <a href="' . admin_url('profile/' . $like['userid']) . '" target="_blank">' . get_staff_full_name($like['userid']) . '</a>
         </div>
     </div></div>';
        }

        echo $likesHtml;
    }
}

/* Comment likes modal to see all comment likes */
public function loadCommentLikesModel()
{
    if ($this->input->post()) {
        $likes = $this->newsfeed_model->loadCommentLikesModel($this->input->post('page'), $this->input->post('commentid'));
        $commentsHtml = '';

        foreach ($likes as $like) {
            $commentsHtml .= '<div class="pull-left modal_like_area"><a href="' . admin_url('profile/' . $like['userid']) . '" target="_blank">' . staff_profile_image($like['userid'], [
                'staff-profile-image-small',
                'no-radius',
            ]) . '</a>
        <div class="media-body">
         <a href="' . admin_url('profile/' . $like['userid']) . '" target="_blank">' . get_staff_full_name($like['userid']) . '</a>
      </div>
  </div></div>';
        }

        echo $commentsHtml;
    }
}

/* Add new newsfeed post */
public function addPost()
{
    if ($this->input->post()) {
        $postid = $this->newsfeed_model->add($this->input->post());

        if ($postid) {
            echo json_encode([
                'postid' => $postid,
            ]);
        }
    }
}

/* Will pin post to top */
public function pinNewsfeedPost($id)
{
    hooks()->do_action('before_pin_post', $id);
    echo json_encode([
        'success' => $this->newsfeed_model->pinPost($id),
    ]);
    $this->session->set_flashdata('newsfeed_auto', true);
}

/* Will unpin post from top */
public function unpinNewsfeedPost($id)
{
    hooks()->do_action('before_unpin_post', $id);
    echo json_encode([
        'success' => $this->newsfeed_model->unpinPost($id),
    ]);
    $this->session->set_flashdata('newsfeed_auto', true);
}

/* Add post attachments */
public function addPostAttachments($id)
{
    handleNewsfeedPostAttachments($id);
}

/* Staff click like button*/
public function likePost($id)
{
    echo json_encode([
        'success' => $this->newsfeed_model->likePost($id),
    ]);
}

/* Staff unlike post */
public function unlikePost($id)
{
    echo json_encode([
        'success' => $this->newsfeed_model->unlikePost($id),
    ]);
}

/* Post new comment by staff */
public function addComment()
{
    $commentId = $this->newsfeed_model->addComment($this->input->post());
    $success = ($commentId !== false ? true : false);
    $comment = '';

    if ($commentId) {
        $comment = $this->commentSingle($this->newsfeed_model->getComment($commentId, true));
    }

    echo json_encode([
        'success' => $success,
        'comment' => $comment,
    ]);
}

/* Like post comment */
public function likeComment($id, $postId)
{
    $success = $this->newsfeed_model->likeComment($id, $postId);
    $comment = $this->commentSingle($this->newsfeed_model->getComment($id, true));

    echo json_encode([
        'success' => $success,
        'comment' => $comment,
    ]);
}

/* Unlike post comment */
public function unlikeComment($id, $postId)
{
    $success = $this->newsfeed_model->unlikeComment($id, $postId);
    $comment = $this->commentSingle($this->newsfeed_model->getComment($id, true));

    echo json_encode([
        'success' => $success,
        'comment' => $comment,
    ]);
}

/* Delete post comment */
public function removePostComment($id, $postId)
{
    echo json_encode([
        'success' => $this->newsfeed_model->removePostComment($id, $postId),
    ]);
}

/* Delete all post */
public function deletePost($postId)
{
    hooks()->do_action('before_delete_post', $postId);
    echo json_encode([
        'success' => $this->newsfeed_model->deletePost($postId),
    ]);
}

}