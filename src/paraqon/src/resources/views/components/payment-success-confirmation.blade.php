{{-- Payment Success / Order Status Email Template --}}

@php
$uniqueId = 'email_order_' . uniqid();
$primaryColor = $data['primaryColor'] ?? '#103947';
$primaryColorBackground = $data['primaryColorBackground'] ?? '#1039473b';
@endphp

<div class="{{ $uniqueId }}">
    {{-- hero-icon-outline --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation"
        style="background-color: {{ $primaryColor }};">
        <tbody>
            <tr>
                <td class="o_bg-ultra_light o_px-md o_py-xl" align="center"
                    style="background-color: {{ $primaryColorBackground }}; padding-left: 24px; padding-right: 24px; padding-top: 44px; padding-bottom: 44px;">
                    <!--[if mso]><table width="584" cellspacing="0" cellpadding="0" border="0" role="presentation"><tbody><tr><td align="center"><![endif]-->
                    <div class="o_col-6s o_sans o_text-md o_text-light o_center"
                        style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 19px; line-height: 28px; max-width: 584px; color: #82899a; text-align: center;">
                        <table class="o_center" cellspacing="0" cellpadding="0" border="0" role="presentation"
                            style="text-align: center; margin-left: auto; margin-right: auto;">
                            <tbody>
                                <tr>
                                    <td class="o_sans o_text o_text-secondary o_b-primary o_px o_py o_br-max" align="center"
                                        style="background-color: {{ $primaryColor }}; font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #424651; border: 2px solid {{ $primaryColor }}; border-radius: 96px; padding-left: 16px; padding-right: 16px; padding-top: 16px; padding-bottom: 16px;">
                                        <img src="https://starsnet-development.oss-cn-hongkong.aliyuncs.com/png/52ce11df-00ed-4a2f-8202-c3c112ea07a9.png"
                                            width="48" height="48" alt=""
                                            style="max-width: 48px; -ms-interpolation-mode: bicubic; vertical-align: middle; border: 0; line-height: 100%; height: auto; outline: none; text-decoration: none;">
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size: 24px; line-height: 24px; height: 24px;">&nbsp;</td>
                                </tr>
                            </tbody>
                        </table>
                        <h2 class="o_heading o_text-dark o_mb-xxs"
                            style="font-family: Helvetica, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 4px; color: #ffffff; font-size: 30px; line-height: 39px;">
                            {{ $data['title'] }}
                        </h2>
                        <p style="font-family: 'Crimson Text', serif; color: #ffffff; margin-top: 0; margin-bottom: 0;">
                            {{ $data['caption'] }}
                        </p>
                    </div>
                    <!--[if mso]></td></tr></table><![endif]-->
                </td>
            </tr>
        </tbody>
    </table>

    {{-- spacer --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white" style="font-size: 24px; line-height: 24px; height: 24px; background-color: #ffffff;">
                    &nbsp;
                </td>
            </tr>
        </tbody>
    </table>

    {{-- order-intro --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white o_px-md o_py" align="center"
                    style="background-color: #ffffff; padding-left: 24px; padding-right: 24px; padding-top: 16px; padding-bottom: 16px;">
                    <!--[if mso]><table width="584" cellspacing="0" cellpadding="0" border="0" role="presentation"><tbody><tr><td align="center"><![endif]-->
                    <div class="o_col-6s o_sans o_text o_text-secondary o_center"
                        style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; max-width: 584px; color: #424651; text-align: center;">
                        <h4 class="o_heading o_text-dark o_mb-xs"
                            style="font-family: Helvetica, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 8px; color: #242b3d; font-size: 18px; line-height: 23px;">
                            {{ $data['orderName'] }}
                        </h4>
                        <p class="o_mb-md"
                            style="font-family: 'Crimson Text', serif; color: {{ $primaryColor }}; margin-top: 0; margin-bottom: 24px;">
                            {{ $data['orderCaption'] }}
                        </p>
                        <table align="center" cellspacing="0" cellpadding="0" border="0" role="presentation">
                            <tbody>
                                <tr>
                                    <td width="300" class="o_btn o_br o_heading o_text" align="center"
                                        style="font-family: Helvetica, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; mso-padding-alt: 12px 24px; background-color: #0ec06e; border-radius: 4px;">
                                        <a class="o_text-white" href="{{ $data['link'] }}"
                                            style="background-color: {{ $primaryColor }}; text-decoration: none; outline: none; color: #ffffff; display: block; padding: 12px 24px; mso-text-raise: 3px;">
                                            {{ $data['buttonText'] }}
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div style="font-size: 28px; line-height: 28px; height: 28px;">&nbsp;</div>
                        <h4 class="o_heading o_text-dark o_mb-xxs"
                            style="font-family: Helvetica, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 4px; color: #242b3d; font-size: 18px; line-height: 23px;">
                            {{ $data['orderTitle'] }}
                        </h4>
                        <p class="o_text-xs o_text-light"
                            style="font-size: 14px; line-height: 21px; color: #82899a; margin-top: 0; margin-bottom: 0;">
                            {{ $data['orderSubtitle'] }}
                        </p>
                    </div>
                    <!--[if mso]></td></tr></table><![endif]-->
                </td>
            </tr>
        </tbody>
    </table>

    {{-- order-details (optional) --}}
    @if(isset($data['showOrderDetails']) && $data['showOrderDetails'])
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_re o_bg-white o_px o_pb-md" align="center"
                    style="font-size: 0; vertical-align: top; background-color: #ffffff; padding-left: 16px; padding-right: 16px; padding-bottom: 24px;">
                    <!--[if mso]><table cellspacing="0" cellpadding="0" border="0" role="presentation"><tbody><tr><td width="300" align="center" valign="top" style="padding: 0px 8px;"><![endif]-->
                    <div class="o_col o_col-3 o_col-full"
                        style="display: inline-block; vertical-align: top; width: 100%; max-width: 300px;">
                        <div style="font-size: 24px; line-height: 24px; height: 24px;">&nbsp;</div>
                        <div class="o_px-xs" style="padding-left: 8px; padding-right: 8px;">
                            <table width="100%" role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tbody>
                                    <tr>
                                        <td class="o_bg-ultra_light o_br o_px o_py o_sans o_text-xs o_text-secondary" align="left"
                                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 14px; line-height: 21px; background-color: #ebf5fa; color: #424651; border-radius: 0; padding-left: 16px; padding-right: 16px; padding-top: 16px; padding-bottom: 16px;">
                                            <p class="o_mb-xs" style="margin-top: 0; margin-bottom: 8px;">
                                                <strong>Billing Information</strong>
                                            </p>
                                            <p class="o_mb-md" style="margin-top: 0; margin-bottom: 24px;">
                                                {{ $data['billDetails']['name'] }}<br>
                                                {{ $data['billDetails']['addressOne'] }}<br>
                                                {{ $data['billDetails']['addressTwo'] }}<br>
                                                {{ $data['billDetails']['addressThree'] }}
                                            </p>
                                            <p class="o_mb-xs" style="margin-top: 0; margin-bottom: 8px;">
                                                <strong>Payment Method</strong>
                                            </p>
                                            <p style="margin-top: 0; margin-bottom: 0;">
                                                {{ $data['billDetails']['methods'] }}
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!--[if mso]></td><td width="300" align="center" valign="top" style="padding: 0px 8px;"><![endif]-->
                    <div class="o_col o_col-3 o_col-full"
                        style="display: inline-block; vertical-align: top; width: 100%; max-width: 300px;">
                        <div style="font-size: 24px; line-height: 24px; height: 24px;">&nbsp;</div>
                        <div class="o_px-xs" style="padding-left: 8px; padding-right: 8px;">
                            <table width="100%" role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tbody>
                                    <tr>
                                        <td class="o_bg-ultra_light o_br o_px o_py o_sans o_text-xs o_text-secondary" align="left"
                                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 14px; line-height: 21px; background-color: #ebf5fa; color: #424651; border-radius: 0; padding-left: 16px; padding-right: 16px; padding-top: 16px; padding-bottom: 16px;">
                                            <p class="o_mb-xs" style="margin-top: 0; margin-bottom: 8px;">
                                                <strong>Shipping Information</strong>
                                            </p>
                                            <p class="o_mb-md" style="margin-top: 0; margin-bottom: 24px;">
                                                {{ $data['shippingInformation']['name'] }}<br>
                                                {{ $data['shippingInformation']['addressOne'] }}<br>
                                                {{ $data['shippingInformation']['addressTwo'] }}<br>
                                                {{ $data['shippingInformation']['addressThree'] }}
                                            </p>
                                            <p class="o_mb-xs" style="margin-top: 0; margin-bottom: 8px;">
                                                <strong>Shipping Method</strong>
                                            </p>
                                            <p style="margin-top: 0; margin-bottom: 0;">
                                                {{ $data['shippingInformation']['methods'] }}
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!--[if mso]></td></tr></table><![endif]-->
                </td>
            </tr>
        </tbody>
    </table>
    @endif

    {{-- order-summary --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white o_sans o_text-xs o_text-light o_px-md o_pt-xs" align="center"
                    style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 14px; line-height: 21px; background-color: #ffffff; color: #82899a; padding-left: 24px; padding-right: 24px; padding-top: 8px;">
                    <p style="margin-top: 0; margin-bottom: 0;">{{ $data['orderSummaryText'] }}</p>
                    <table cellspacing="0" cellpadding="0" border="0" role="presentation">
                        <tbody>
                            <tr>
                                <td width="584" class="o_re o_bb-light"
                                    style="font-size: 8px; line-height: 8px; height: 8px; vertical-align: top; border-bottom: 1px solid #d3dce0;">
                                    &nbsp;
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>
    {{-- product items --}}
    @foreach($data['productItems'] as $item)
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_re o_bg-white o_px o_pt" align="center"
                    style="font-size: 0; vertical-align: top; background-color: #ffffff; padding-left: 16px; padding-right: 16px; padding-top: 16px;">
                    <!--[if mso]><table cellspacing="0" cellpadding="0" border="0" role="presentation"><tbody><tr><td width="200" align="center" valign="top" style="padding: 0px 8px;"><![endif]-->
                    <div class="o_col o_col-2 o_col-full"
                        style="display: inline-block; vertical-align: top; width: 100%; max-width: 200px;">
                        <div class="o_px-xs o_sans o_text o_center"
                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; text-align: center; padding-left: 8px; padding-right: 8px;">
                            <p style="margin-top: 0; margin-bottom: 0;">
                                <a class="o_text-primary" href="{{ $item['productLink'] ?? 'https://example.com/' }}"
                                    style="text-decoration: none; outline: none; color: {{ $primaryColor }};">
                                    <img src="{{ $item['imageUrl'] }}" width="184" height="184" alt=""
                                        style="max-width: 184px; -ms-interpolation-mode: bicubic; vertical-align: middle; border: 0; line-height: 100%; height: auto; outline: none; text-decoration: none;">
                                </a>
                            </p>
                        </div>
                    </div>
                    <!--[if mso]></td><td width="300" align="left" valign="top" style="padding: 0px 8px;"><![endif]-->
                    <div class="o_col o_col-3 o_col-full"
                        style="display: inline-block; vertical-align: top; width: 100%; max-width: 300px;">
                        <div class="o_px-xs o_sans o_text o_text-light o_left o_xs-center"
                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #82899a; text-align: left; padding-left: 8px; padding-right: 8px;">
                            <h4 class="o_heading o_text-dark o_mb-xxs"
                                style="font-family: Helvetica, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 4px; color: #242b3d; font-size: 18px; line-height: 23px;">
                                {{ $item['name'] }}
                            </h4>
                            <p class="o_text-secondary o_mb-xs"
                                style="color: #424651; margin-top: 0; margin-bottom: 8px;">
                                {{ $item['caption'] }}
                            </p>
                            @foreach($item['descriptions'] as $line)
                            <p class="o_text-xs o_mb-xs"
                                style="font-size: 14px; line-height: 21px; margin-top: 0; margin-bottom: 8px;">
                                {{ $line }}<br>
                            </p>
                            @endforeach
                        </div>
                    </div>
                    <!--[if mso]></td><td width="100" align="right" valign="top" style="padding: 0px 8px;"><![endif]-->
                    <div class="o_col o_col-1 o_col-full"
                        style="display: inline-block; vertical-align: top; width: 100%; max-width: 100px;">
                        <div class="o_px-xs o_sans o_text o_text-secondary o_right o_xs-center"
                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #424651; text-align: right; padding-left: 8px; padding-right: 8px;">
                            <p style="margin-top: 0; margin-bottom: 0;">{{ $item['price'] }}</p>
                        </div>
                    </div>
                    <!--[if mso]></td></tr><tr><td colspan="3" style="padding: 0px 8px;"><![endif]-->
                    <div class="o_px-xs" style="padding-left: 8px; padding-right: 8px;">
                        <table cellspacing="0" cellpadding="0" border="0" role="presentation">
                            <tbody>
                                <tr>
                                    <td width="584" class="o_re o_bb-light"
                                        style="font-size: 16px; line-height: 16px; height: 16px; vertical-align: top; border-bottom: 1px solid #d3dce0;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!--[if mso]></td></tr></table><![endif]-->
                </td>
            </tr>
        </tbody>
    </table>
    @endforeach

    {{-- invoice-total --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_re o_bg-white o_px-md o_py" align="center"
                    style="font-size: 0; vertical-align: top; background-color: #ffffff; padding-left: 24px; padding-right: 24px; padding-top: 16px; padding-bottom: 16px;">
                    <!--[if mso]><table width="584" cellspacing="0" cellpadding="0" border="0" role="presentation"><tbody><tr><td align="right"><![endif]-->
                    <div class="o_col-6s o_right" style="max-width: 584px; text-align: right;">
                        <table class="o_right" role="presentation" cellspacing="0" cellpadding="0" border="0"
                            style="text-align: right; margin-left: auto; margin-right: 0;">
                            <tbody>
                                <tr>
                                    <td width="284" align="left">
                                        <table width="100%" role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tbody>
                                                <tr>
                                                    <td width="50%" class="o_pt-xs" align="left" style="padding-top: 8px;">
                                                        <p class="o_sans o_text o_text-secondary"
                                                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #424651;">
                                                            {{ $data['subtotalText'] }}
                                                        </p>
                                                    </td>
                                                    <td width="50%" class="o_pt-xs" align="right" style="padding-top: 8px;">
                                                        <p class="o_sans o_text o_text-secondary"
                                                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #424651;">
                                                            {{ $data['subtotalValue'] }}
                                                        </p>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td width="50%" class="o_pt-xs" align="left" style="padding-top: 8px;">
                                                        <p class="o_sans o_text o_text-secondary"
                                                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #424651;">
                                                            {{ $data['shippingText'] }}
                                                        </p>
                                                    </td>
                                                    <td width="50%" class="o_pt-xs" align="right" style="padding-top: 8px;">
                                                        <p class="o_sans o_text o_text-secondary"
                                                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #424651;">
                                                            {{ $data['shippingValue'] }}
                                                        </p>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="o_pt o_bb-light"
                                                        style="border-bottom: 1px solid #d3dce0; padding-top: 16px;">
                                                        &nbsp;
                                                    </td>
                                                    <td class="o_pt o_bb-light"
                                                        style="border-bottom: 1px solid #d3dce0; padding-top: 16px;">
                                                        &nbsp;
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td width="50%" class="o_pt" align="left" style="padding-top: 16px;">
                                                        <p class="o_sans o_text o_text-secondary"
                                                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #424651;">
                                                            <strong>{{ $data['totalText'] }}</strong>
                                                        </p>
                                                    </td>
                                                    <td width="50%" class="o_pt" align="right" style="padding-top: 16px;">
                                                        <p class="o_sans o_text o_text-primary"
                                                            style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; color: {{ $primaryColor }};">
                                                            <strong>{{ $data['totalValue'] }}</strong>
                                                        </p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!--[if mso]></td></tr></table><![endif]-->
                </td>
            </tr>
        </tbody>
    </table>

    {{-- spacer --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white" style="font-size: 24px; line-height: 24px; height: 24px; background-color: #ffffff;">
                    &nbsp;
                </td>
            </tr>
        </tbody>
    </table>
</div>

{{-- prettier-ignore-start --}}
<style>
    @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Roboto:wght@400;700&family=Crimson+Text&display=swap');

    /* Scoped styles for {{ $uniqueId }} */
    .{{ $uniqueId }} a {
        text-decoration: none;
        outline: none;
    }

    @media (max-width: 649px) {
        .{{ $uniqueId }} .o_col-full {
            max-width: 100% !important;
        }

        .{{ $uniqueId }} .o_col-half {
            max-width: 50% !important;
        }

        .{{ $uniqueId }} .o_hide-lg {
            display: inline-block !important;
            font-size: inherit !important;
            max-height: none !important;
            line-height: inherit !important;
            overflow: visible !important;
            width: auto !important;
            visibility: visible !important;
        }

        .{{ $uniqueId }} .o_hide-xs,
        .{{ $uniqueId }} .o_hide-xs.o_col_i {
            display: none !important;
            font-size: 0 !important;
            max-height: 0 !important;
            width: 0 !important;
            line-height: 0 !important;
            overflow: hidden !important;
            visibility: hidden !important;
            height: 0 !important;
        }

        .{{ $uniqueId }} .o_xs-center {
            text-align: center !important;
        }

        .{{ $uniqueId }} .o_xs-left {
            text-align: left !important;
        }

        .{{ $uniqueId }} .o_xs-right {
            text-align: left !important;
        }

        .{{ $uniqueId }} table.o_xs-left {
            margin-left: 0 !important;
            margin-right: auto !important;
            float: none !important;
        }

        .{{ $uniqueId }} table.o_xs-right {
            margin-left: auto !important;
            margin-right: 0 !important;
            float: none !important;
        }

        .{{ $uniqueId }} table.o_xs-center {
            margin-left: auto !important;
            margin-right: auto !important;
            float: none !important;
        }

        .{{ $uniqueId }} h1.o_heading {
            font-size: 32px !important;
            line-height: 41px !important;
        }

        .{{ $uniqueId }} h2.o_heading {
            font-size: 26px !important;
            line-height: 37px !important;
        }

        .{{ $uniqueId }} h3.o_heading {
            font-size: 20px !important;
            line-height: 30px !important;
        }

        .{{ $uniqueId }} .o_xs-py-md {
            padding-top: 24px !important;
            padding-bottom: 24px !important;
        }

        .{{ $uniqueId }} .o_xs-pt-xs {
            padding-top: 8px !important;
        }

        .{{ $uniqueId }} .o_xs-pb-xs {
            padding-bottom: 8px !important;
        }
    }

    @media screen {
        .{{ $uniqueId }} .o_sans,
        .{{ $uniqueId }} .o_heading {
            font-family: "Montserrat", sans-serif !important;
        }

        .{{ $uniqueId }} .o_heading,
        .{{ $uniqueId }} strong,
        .{{ $uniqueId }} b {
            font-weight: 700 !important;
        }

        .{{ $uniqueId }} a[x-apple-data-detectors] {
            color: inherit !important;
            text-decoration: none !important;
        }
    }
</style>
{{-- prettier-ignore-end --}}