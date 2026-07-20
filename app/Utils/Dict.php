<?php

namespace App\Utils;

class Dict
{
    CONST EMAIL_WHITELIST_SUFFIX_DEFAULT = [
        'gmail.com',
        'qq.com',
        '163.com',
        'yahoo.com',
        'sina.com',
        '126.com',
        'outlook.com',
        'yeah.net',
        'foxmail.com'
    ];
    CONST WITHDRAW_METHOD_WHITELIST_DEFAULT = [
        '支付宝',
        'USDT',
        'Paypal'
    ];

    /**
     * uTLS 指纹默认列表（可在系统设置中编辑的具体指纹）。
     * random / randomized 为元选项，不在此列表中，读取时自动追加。
     *
     * @see UTLS_FINGERPRINT_META
     */
    public const UTLS_FINGERPRINTS_DEFAULT = [
        'chrome',
        'firefox',
        'safari',
        'ios',
        'android',
        'edge',
        'qq',
    ];

    /**
     * uTLS 元选项：不参与系统设置编辑，下发节点表单时始终追加。
     * - random：订阅生成时从具体指纹池随机抽取
     * - randomized：由客户端自行随机
     */
    public const UTLS_FINGERPRINT_META = [
        'random',
        'randomized',
    ];
}
