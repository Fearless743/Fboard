<?php

namespace App\Http\Requests\Admin;

use App\Utils\Helper;
use Illuminate\Foundation\Http\FormRequest;

class KnowledgeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'category' => 'required',
            'language' => 'required',
            'title' => 'required',
            'body' => 'required',
            'show' => 'nullable|boolean'
        ];
    }

    public function messages()
    {
        return [
            'title.required' => '标题不能为空',
            'category.required' => '分类不能为空',
            'body.required' => '内容不能为空',
            'language.required' => '语言不能为空',
            'show.boolean' => '显示状态必须为布尔值'
        ];
    }

    /** 服务端再清洗一次富文本，防止绕过前端 DOMPurify 直接写接口 */
    protected function prepareForValidation(): void
    {
        if ($this->has('body')) {
            $this->merge(['body' => Helper::sanitizeHtml($this->input('body'))]);
        }
    }
}
