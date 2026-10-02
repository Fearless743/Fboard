<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use App\Traits\QueryOperators;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    use QueryOperators;

    private function applyFiltersAndSorts(Request $request, $builder)
    {
        if ($request->has('filter')) {
            collect($request->input('filter'))->each(function ($filter) use ($builder) {
                $key = $filter['id'];
                $value = $filter['value'];
                if (!is_string($key) || !$this->isSafeQueryField($key)) {
                    return;
                }
                $builder->where(function ($query) use ($key, $value) {
                    if (is_array($value)) {
                        $query->whereIn($key, $value);
                    } else {
                        $query->where($key, 'like', "%{$value}%");
                        // subject 字段支持拼音索引匹配
                        if ($key === 'subject' && $builder->getModel() instanceof \App\Models\Ticket) {
                            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $value);
                            $query->orWhere('pinyin_index', 'like', "%{$escaped}%");
                        }
                    }
                });
            });
        }

        if ($request->has('sort')) {
            collect($request->input('sort'))->each(function ($sort) use ($builder) {
                $key = $sort['id'];
                if (!is_string($key) || !$this->isSafeQueryField($key)) {
                    return;
                }
                $value = $sort['desc'] ? 'DESC' : 'ASC';
                $builder->orderBy($key, $value);
            });
        }
    }
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            return $this->fetchTicketById($request);
        } else {
            return $this->fetchTickets($request);
        }
    }

    /**
     * Summary of fetchTicketById
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function fetchTicketById(Request $request)
    {
        $ticket = Ticket::with('messages', 'user')->find($request->input('id'));

        if (!$ticket) {
            return $this->fail([400202, '工单不存在']);
        }
        $ticket->messages->each(fn($msg) => $msg->setRelation('ticket', $ticket));
        $result = $ticket->toArray();
        $result['user'] = UserController::transformUserData($ticket->user);

        return $this->success($result);
    }

    /**
     * Summary of fetchTickets
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    private function fetchTickets(Request $request)
    {
        $ticketModel = Ticket::with('user')
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->has('reply_status'), function ($query) use ($request) {
                $query->whereIn('reply_status', $request->input('reply_status'));
            })
            ->when($request->has('email'), function ($query) use ($request) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('email', $request->input('email'));
                });
            });

        $this->applyFiltersAndSorts($request, $ticketModel);
        $tickets = $ticketModel
            ->latest('updated_at')
            ->paginate(
                perPage: $request->integer('pageSize', 10),
                page: $request->integer('current', 1)
            );

        // 获取items然后映射转换
        $items = collect($tickets->items())->map(function ($ticket) {
            $ticketData = $ticket->toArray();
            $ticketData['user'] = UserController::transformUserData($ticket->user);
            return $ticketData;
        })->all();

        return response([
            'data' => $items,
            'total' => $tickets->total()
        ]);
    }

    public function reply(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric',
            'message' => 'required|string'
        ], [
            'id.required' => '工单ID不能为空',
            'message.required' => '消息不能为空'
        ]);
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $request->input('id'),
            $request->input('message'),
            $request->user()->id
        );
        return $this->success(true);
    }

    public function close(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '工单ID不能为空'
        ]);
        try {
            $ticket = Ticket::findOrFail($request->input('id'));
        } catch (ModelNotFoundException $e) {
            return $this->fail([400202, '工单不存在']);
        }

        HookManager::call('admin.ticket.close.before', [
            'ticket' => $ticket,
            'request' => $request,
        ]);

        try {
            $ticket->status = Ticket::STATUS_CLOSED;
            $ticket->save();

            HookManager::call('admin.ticket.close.after', [
                'ticket' => $ticket,
                'request' => $request,
            ]);

            return $this->success(true);
        } catch (\Exception $e) {
            return $this->fail([500101, '关闭失败']);
        }
    }

    public function bulkClose(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'remark' => 'nullable|string|max:500',
        ], [
            'ids.required' => '请选择要关闭的工单',
            'ids.array' => '工单ID格式不正确',
            'ids.min' => '请选择要关闭的工单',
            'ids.*.integer' => '工单ID格式不正确',
            'remark.max' => '备注不能超过500字符',
        ]);

        $ids = $request->input('ids');

        try {
            HookManager::call('admin.ticket.bulk_close.before', [
                'ids' => $ids,
                'request' => $request,
            ]);

            // 仅关闭选中且仍在处理中的工单，已关闭的跳过，避免重复刷新 updated_at。
            $count = Ticket::whereIn('id', $ids)
                ->where('status', '!=', Ticket::STATUS_CLOSED)
                ->update([
                    'status' => Ticket::STATUS_CLOSED,
                    'updated_at' => now()->timestamp,
                ]);

            HookManager::call('admin.ticket.bulk_close.after', [
                'ids' => $ids,
                'count' => $count,
                'request' => $request,
            ]);

            return $this->success(['count' => $count]);
        } catch (\Exception $e) {
            return $this->fail([500101, '批量关闭失败']);
        }
    }

    public function show($ticketId)
    {
        $ticket = Ticket::with([
            'user',
            'messages' => function ($query) {
                $query->with(['user']);
            }
        ])->findOrFail($ticketId);

        $ticket->messages->each(fn($msg) => $msg->setRelation('ticket', $ticket));

        return response()->json([
            'data' => $ticket
        ]);
    }
}
