<?php

namespace App\Http\Requests\Admin;

use App\Utils\Helper;
use Illuminate\Foundation\Http\FormRequest;

class NoticeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title' => 'required',
            'content' => 'required',
            'img_url' => 'nullable|url',
            'tags' => 'nullable|array'
        ];
    }

    public function messages()
    {
        return [
            'title.required' => '标题不能为空',
            'content.required' => '内容不能为空',
            'img_url.url' => '图片URL格式不正确',
            'tags.array' => '标签格式不正确'
        ];
    }

    /** 服务端消毒公告内容（可能为富文本），避免直接调 API 写入恶意 HTML */
    protected function prepareForValidation(): void
    {
        if ($this->has('content')) {
            $this->merge(['content' => Helper::sanitizeHtml($this->input('content'))]);
        }
    }
}
