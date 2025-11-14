{{-- Login 2FA Verification Code Email Template --}}

@php
$uniqueId = 'email_2fa_login_' . uniqid();
$primaryColor = $data['primaryColor'] ?? '#103947';
@endphp

<div class="{{ $uniqueId }}">
    {{-- hero-primary-icon-outline --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-primary o_px-md o_py-xl" align="center"
                    style="background-color: {{ $primaryColor }}; padding-left: 24px; padding-right: 24px; padding-top: 44px; padding-bottom: 44px;">
                    <!--[if mso]><table width="584" cellspacing="0" cellpadding="0" border="0" role="presentation"><tbody><tr><td align="center"><![endif]-->
                    <div class="o_col-6s o_sans o_text-md o_text-white o_center"
                        style="font-family: Montserrat, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 19px; line-height: 28px; max-width: 584px; color: #ffffff; text-align: center;">
                        <table class="o_center" cellspacing="0" cellpadding="0" border="0" role="presentation"
                            style="text-align: center; margin-left: auto; margin-right: auto;">
                            <tbody>
                                <tr>
                                    <td class="o_sans o_text o_text-white o_b-white o_px o_py o_br-max" align="center"
                                        style="font-family: Montserrat, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #ffffff; border: 2px solid #ffffff; border-radius: 96px; padding-left: 16px; padding-right: 16px; padding-top: 16px; padding-bottom: 16px;">
                                        <img src="https://starsnet-development.oss-cn-hongkong.aliyuncs.com/png/8967c3a1-a2d5-4e6f-86ce-e0434c6185e0.png"
                                            width="48" height="48" alt=""
                                            style="max-width: 48px; -ms-interpolation-mode: bicubic; vertical-align: middle; border: 0; line-height: 100%; height: auto; outline: none; text-decoration: none;">
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size: 24px; line-height: 24px; height: 24px;">&nbsp;</td>
                                </tr>
                            </tbody>
                        </table>
                        <h2 class="o_heading o_mb-xxs"
                            style="font-family: Montserrat, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 4px; font-size: 30px; line-height: 39px;">
                            {{ $data['title'] }}
                        </h2>
                        <p style="margin-top: 0; margin-bottom: 0; font-family: 'Crimson Text', serif;">
                            {{ $data['mainCaption'] }}
                        </p>
                    </div>
                    <!--[if mso]></td></tr></table><![endif]-->
                </td>
            </tr>
        </tbody>
    </table>

    {{-- spacer-lg --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white" style="font-size: 24px; line-height: 24px; height: 24px; background-color: #ffffff;">
                    &nbsp;
                </td>
            </tr>
        </tbody>
    </table>

    {{-- label-lg --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white o_px-md o_py o_sans o_text-xs o_text-light" align="center"
                    style="font-family: Montserrat, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 19px; line-height: 21px; background-color: #ffffff; color: {{ $primaryColor }}; padding-left: 24px; padding-right: 24px; padding-top: 16px; padding-bottom: 16px;">
                    <p class="o_mb" style="margin-top: 0; margin-bottom: 16px;"><strong>{{ $data['codeCaption'] }}</strong></p>
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                        <tbody>
                            <tr>
                                <td width="384" class="o_bg-ultra_light o_br o_text-md o_sans o_px-xs o_py-md" align="center"
                                    style="font-family: Montserrat, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 19px; line-height: 28px; background-color: #f5f5f5; border-radius: 0; padding-left: 8px; padding-right: 8px; padding-top: 24px; padding-bottom: 24px;">
                                    <p class="o_text-dark" style="color: {{ $primaryColor }}; margin-top: 0; margin-bottom: 0;">
                                        <strong>{{ $data['code'] }}</strong>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>

    {{-- button-success --}}
    @if(isset($data['link']) || isset($data['buttonText']))
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white o_px-md o_py-xs" align="center"
                    style="background-color: #ffffff; padding-left: 24px; padding-right: 24px; padding-top: 8px; padding-bottom: 8px;">
                    <table align="center" cellspacing="0" cellpadding="0" border="0" role="presentation">
                        <tbody>
                            <tr>
                                <td width="300" class="o_btn o_br o_heading o_text" align="center"
                                    style="font-family: Montserrat, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; mso-padding-alt: 12px 24px; background-color: {{ $primaryColor }}; border-radius: 0;">
                                    <a class="o_text-white" href="{{ $data['link'] ?? '#' }}"
                                        style="text-decoration: none; outline: none; color: #ffffff; display: block; padding: 12px 24px; mso-text-raise: 3px;">
                                        {{ $data['buttonText'] }}
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>
    @endif

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

    {{-- alert-dark --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white o_px-md o_py" align="center"
                    style="background-color: #ffffff; padding-left: 24px; padding-right: 24px; padding-top: 16px; padding-bottom: 16px;">
                    <!--[if mso]><table width="584" cellspacing="0" cellpadding="0" border="0" role="presentation"><tbody><tr><td align="center"><![endif]-->
                    <div class="o_col-6s" style="max-width: 584px;">
                        <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
                            <tbody>
                                <tr>
                                    <td width="40" class="o_bg-dark o_br-l o_text-md o_text-white o_sans o_py-xs" align="right"
                                        style="font-family: Montserrat, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 19px; line-height: 28px; background-color: {{ $primaryColor }}; color: #ffffff; border-radius: 0; padding-top: 8px; padding-bottom: 8px;">
                                        <img src="https://starsnet-development.oss-cn-hongkong.aliyuncs.com/png/6da1e114-1b24-4461-9ccd-78a9487c1f87.png"
                                            width="24" height="24" alt=""
                                            style="max-width: 24px; -ms-interpolation-mode: bicubic; vertical-align: top; border: 0; line-height: 100%; height: auto; outline: none; text-decoration: none;">
                                    </td>
                                    <td class="o_bg-dark o_br-r o_text-xs o_text-white o_sans o_px o_py-xs" align="left"
                                        style="margin-top: 0; margin-bottom: 0; font-size: 14px; line-height: 21px; background-color: {{ $primaryColor }}; color: #ffffff; border-radius: 0 4px 4px 0; padding-left: 16px; padding-right: 16px; padding-top: 8px; padding-bottom: 8px;">
                                        <p style="margin-top: 0; margin-bottom: 0; font-size: 16px; font-family: 'Crimson Text', serif;">
                                            {{ $data['warningCaption'] }}
                                        </p>
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
    @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Crimson+Text&display=swap');

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
            font-family: "Montserrat", Arial, sans-serif !important;
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