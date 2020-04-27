<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Texture;
use App\Models\User;
use Auth;
use Blessing\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Option;
use Parsedown;
use Storage;

class SkinlibController extends Controller
{
    /**
     * Map error code of file uploading to human-readable text.
     *
     * @see http://php.net/manual/en/features.file-upload.errors.php
     *
     * @var array
     */
    public static $phpFileUploadErrors = [
        0 => 'There is no error, the file uploaded with success',
        1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
        3 => 'The uploaded file was only partially uploaded',
        4 => 'No file was uploaded',
        6 => 'Missing a temporary folder',
        7 => 'Failed to write file to disk.',
        8 => 'A PHP extension stopped the file upload.',
    ];

    public function library(Request $request)
    {
        $user = Auth::user();

        // Available filters: skin, steve, alex, cape
        $type = $request->input('filter', 'skin');
        $uploader = $request->input('uploader');
        $keyword = $request->input('keyword');
        $sort = $request->input('sort', 'time');
        $sortBy = $sort == 'time' ? 'upload_at' : $sort;

        return Texture::orderBy($sortBy, 'desc')
            ->when($type === 'skin', function (Builder $query) {
                return $query->whereIn('type', ['steve', 'alex']);
            }, function (Builder $query) use ($type) {
                return $query->where('type', $type);
            })
            ->when($keyword, function (Builder $query, $keyword) {
                return $query->like('name', $keyword);
            })
            ->when($uploader, function (Builder $query, $uploader) {
                return $query->where('uploader', $uploader);
            })
            ->when($user, function (Builder $query, User $user) {
                if (!$user->isAdmin()) {
                    // use closure-style `where` clause to lift up SQL priority
                    return $query->where(function (Builder $query) use ($user) {
                        $query
                            ->where('public', true)
                            ->orWhere('uploader', $user->uid);
                    });
                }
            }, function (Builder $query) {
                // show public textures only to anonymous visitors
                return $query->where('public', true);
            })
            ->join('users', 'uid', 'uploader')
            ->select(['tid', 'name', 'type', 'uploader', 'public', 'likes', 'nickname'])
            ->paginate(20);
    }

    public function show(Filter $filter, $tid)
    {
        $texture = Texture::find($tid);
        $user = Auth::user();

        if (!$texture || $texture && !Storage::disk('textures')->has($texture->hash)) {
            if (option('auto_del_invalid_texture')) {
                if ($texture) {
                    $texture->delete();
                }

                abort(404, trans('skinlib.show.deleted'));
            }
            abort(404, trans('skinlib.show.deleted').trans('skinlib.show.contact-admin'));
        }

        if (!$texture->public) {
            if (!Auth::check() || ($user->uid != $texture->uploader && !$user->isAdmin())) {
                abort(option('status_code_for_private'), trans('skinlib.show.private'));
            }
        }

        $badges = [];
        $uploader = $texture->owner;
        if ($uploader) {
            if ($uploader->isAdmin()) {
                $badges[] = ['text' => 'STAFF', 'color' => 'primary'];
            }

            $badges = $filter->apply('user_badges', $badges, [$uploader]);
        }

        $grid = [
            'layout' => [
                ['md-8', 'md-4'],
            ],
            'widgets' => [
                [
                    ['shared.previewer'],
                    ['skinlib.widgets.show.side'],
                ],
            ],
        ];
        $grid = $filter->apply('grid:skinlib.show', $grid);

        return view('skinlib.show')
            ->with('texture', $texture)
            ->with('grid', $grid)
            ->with('extra', [
                'download' => option('allow_downloading_texture'),
                'currentUid' => $user ? $user->uid : 0,
                'admin' => $user && $user->isAdmin(),
                'inCloset' => $user && $user->closet()->where('tid', $texture->tid)->count() > 0,
                'uploaderExists' => (bool) $uploader,
                'nickname' => optional($uploader)->nickname ?? trans('general.unexistent-user'),
                'report' => intval(option('reporter_score_modification', 0)),
                'badges' => $badges,
            ]);
    }

    public function info($tid)
    {
        if ($t = Texture::find($tid)) {
            return json('', 0, $t->toArray());
        } else {
            return abort(404);
        }
    }

    public function upload(Filter $filter)
    {
        $grid = [
            'layout' => [
                ['md-6', 'md-6'],
            ],
            'widgets' => [
                [
                    ['skinlib.widgets.upload.input'],
                    ['shared.previewer'],
                ],
            ],
        ];
        $grid = $filter->apply('grid:skinlib.upload', $grid);

        $parsedown = new Parsedown();

        return view('skinlib.upload')
            ->with('grid', $grid)
            ->with('extra', [
                'rule' => ($regexp = option('texture_name_regexp'))
                    ? trans('skinlib.upload.name-rule-regexp', compact('regexp'))
                    : trans('skinlib.upload.name-rule'),
                'privacyNotice' => trans(
                    'skinlib.upload.private-score-notice',
                    ['score' => option('private_score_per_storage')]
                ),
                'scorePublic' => intval(option('score_per_storage')),
                'scorePrivate' => intval(option('private_score_per_storage')),
                'closetItemCost' => intval(option('score_per_closet_item')),
                'award' => intval(option('score_award_per_texture')),
                'contentPolicy' => $parsedown->text(option_localized('content_policy')),
            ]);
    }

    public function handleUpload(Request $request)
    {
        $user = Auth::user();

        if (($response = $this->checkUpload($request)) instanceof JsonResponse) {
            return $response;
        }

        $file = $request->file('file');
        $responses = event(new \App\Events\HashingFile($file));
        if (isset($responses[0]) && is_string($responses[0])) {
            return $responses[0];  // @codeCoverageIgnore
        }

        $t = new Texture();
        $t->name = $request->input('name');
        $t->type = $request->input('type');
        $t->hash = hash_file('sha256', $file);
        $t->size = ceil($request->file('file')->getSize() / 1024);
        $t->public = $request->input('public') == 'true';
        $t->uploader = $user->uid;

        $cost = $t->size * ($t->public ? Option::get('score_per_storage') : Option::get('private_score_per_storage'));
        $cost += option('score_per_closet_item');
        $cost -= option('score_award_per_texture', 0);

        if ($user->score < $cost) {
            return json(trans('skinlib.upload.lack-score'), 7);
        }

        $repeated = Texture::where('hash', $t->hash)->where('public', true)->first();
        if ($repeated) {
            // if the texture already uploaded was set to private,
            // then allow to re-upload it.
            return json(trans('skinlib.upload.repeated'), 2, ['tid' => $repeated->tid]);
        }

        if (Storage::disk('textures')->missing($t->hash)) {
            Storage::disk('textures')->put($t->hash, file_get_contents($request->file('file')));
        }

        $t->likes++;
        $t->save();

        $user->score -= $cost;
        $user->closet()->attach($t->tid, ['item_name' => $t->name]);
        $user->save();

        return json(trans('skinlib.upload.success', ['name' => $request->input('name')]), 0, [
            'tid' => $t->tid,
        ]);
    }

    // @codeCoverageIgnore

    public function delete(Request $request)
    {
        $texture = Texture::find($request->tid);
        $user = Auth::user();

        if (!$texture) {
            return json(trans('skinlib.non-existent'), 1);
        }

        if ($texture->uploader != $user->uid && !$user->isAdmin()) {
            return json(trans('skinlib.no-permission'), 1);
        }

        // check if file occupied
        if (Texture::where('hash', $texture->hash)->count() == 1) {
            Storage::disk('textures')->delete($texture->hash);
        }

        $texture->delete();

        return json(trans('skinlib.delete.success'), 0);
    }

    public function privacy(Request $request)
    {
        $t = Texture::find($request->input('tid'));
        $user = $request->user();

        if (!$t) {
            return json(trans('skinlib.non-existent'), 1);
        }

        if ($t->uploader != $user->uid && !$user->isAdmin()) {
            return json(trans('skinlib.no-permission'), 1);
        }

        $uploader = User::find($t->uploader);
        $score_diff = $t->size * (option('private_score_per_storage') - option('score_per_storage')) * ($t->public ? -1 : 1);
        if ($t->public && option('take_back_scores_after_deletion', true)) {
            $score_diff -= option('score_award_per_texture', 0);
        }
        if ($uploader->score + $score_diff < 0) {
            return json(trans('skinlib.upload.lack-score'), 1);
        }

        $type = $t->type == 'cape' ? 'cape' : 'skin';
        Player::where("tid_$type", $t->tid)
            ->where('uid', '<>', session('uid'))
            ->update(["tid_$type" => 0]);

        $t->likers()->get()->each(function ($user) use ($t) {
            $user->closet()->detach($t->tid);
            if (option('return_score')) {
                $user->score += option('score_per_closet_item');
                $user->save();
            }
            $t->likes--;
        });

        $uploader->score += $score_diff;
        $uploader->save();

        $t->public = !$t->public;
        $t->save();

        return json(
            trans('skinlib.privacy.success', ['privacy' => (!$t->public ? trans('general.private') : trans('general.public'))]),
            0
        );
    }

    public function rename(Request $request)
    {
        $this->validate($request, [
            'tid' => 'required|integer',
            'new_name' => 'required',
        ]);
        $user = $request->user();
        $t = Texture::find($request->input('tid'));

        if (!$t) {
            return json(trans('skinlib.non-existent'), 1);
        }

        if ($t->uploader != $user->uid && !$user->isAdmin()) {
            return json(trans('skinlib.no-permission'), 1);
        }

        $t->name = $request->input('new_name');

        if ($t->save()) {
            return json(trans('skinlib.rename.success', ['name' => $request->input('new_name')]), 0);
        }
    }

    // @codeCoverageIgnore

    public function model(Request $request)
    {
        $user = $request->user();
        $data = $this->validate($request, [
            'tid' => 'required|integer',
            'model' => 'required|in:steve,alex,cape',
        ]);

        $t = Texture::find($request->input('tid'));

        if (!$t) {
            return json(trans('skinlib.non-existent'), 1);
        }

        if ($t->uploader != $user->uid && !$user->isAdmin()) {
            return json(trans('skinlib.no-permission'), 1);
        }

        $t->type = $request->input('model');
        $t->save();

        return json(trans('skinlib.model.success', ['model' => $data['model']]), 0);
    }

    protected function checkUpload(Request $request)
    {
        if ($file = $request->files->get('file')) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return json(static::$phpFileUploadErrors[$file->getError()], $file->getError());
            }
        }

        $this->validate($request, [
            'name' => [
                'required',
                option('texture_name_regexp') ? 'regex:'.option('texture_name_regexp') : 'string',
            ],
            'file' => 'required|max:'.option('max_upload_file_size'),
            'public' => 'required',
        ]);

        $mime = $request->file('file')->getMimeType();
        if ($mime != 'image/png' && $mime != 'image/x-png') {
            return json(trans('skinlib.upload.type-error'), 1);
        }

        $type = $request->input('type');
        $size = getimagesize($request->file('file'));
        $ratio = $size[0] / $size[1];

        if ($type == 'steve' || $type == 'alex') {
            if ($ratio != 2 && $ratio != 1) {
                return json(trans('skinlib.upload.invalid-size', ['type' => trans('general.skin'), 'width' => $size[0], 'height' => $size[1]]), 1);
            }
            if ($size[0] % 64 != 0 || $size[1] % 32 != 0) {
                return json(trans('skinlib.upload.invalid-hd-skin', ['type' => trans('general.skin'), 'width' => $size[0], 'height' => $size[1]]), 1);
            }
        } elseif ($type == 'cape') {
            if ($ratio != 2) {
                return json(trans('skinlib.upload.invalid-size', ['type' => trans('general.cape'), 'width' => $size[0], 'height' => $size[1]]), 1);
            }
        } else {
            return json(trans('general.illegal-parameters'), 1);
        }
    }

    // @codeCoverageIgnore
}
