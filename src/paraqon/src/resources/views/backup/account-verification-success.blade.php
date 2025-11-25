{{-- Account Verification Success Email Template --}}

@php
$uniqueId = 'email_verification_success_' . uniqid();
$primaryColor = $data['primaryColor'] ?? '#103947';
$primaryColorBackground = $data['primaryColorBackground'] ?? '#1039473b';
@endphp

<div class="{{ $uniqueId }}">
    {{-- hero-icon --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
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
                                    <td class="o_sans o_text o_text-white o_bg-primary o_px o_py o_br-max" align="center"
                                        style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; background-color: {{ $primaryColor }}; color: #ffffff; border-radius: 96px; padding-left: 16px; padding-right: 16px; padding-top: 16px; padding-bottom: 16px;">
                                        <img src="https://starsnet-development.oss-cn-hongkong.aliyuncs.com/png/82790be1-7318-456f-8f1b-464144857752.png"
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
                            style="font-family: Helvetica, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 4px; color: {{ $primaryColor }}; font-size: 30px; line-height: 39px;">
                            {{ $data['title'] }}
                        </h2>
                        <p style="font-family: 'Crimson Text', serif; margin-top: 0; margin-bottom: 0; color: {{ $primaryColor }};">
                            {{ $data['caption'] }}
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

    {{-- button-primary --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white o_px-md o_py-xs" align="center"
                    style="background-color: #ffffff; padding-left: 24px; padding-right: 24px; padding-top: 8px; padding-bottom: 8px;">
                    <table align="center" cellspacing="0" cellpadding="0" border="0" role="presentation">
                        <tbody>
                            <tr>
                                <td width="300" class="o_btn o_bg-primary o_br o_heading o_text" align="center"
                                    style="font-family: Helvetica, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; mso-padding-alt: 12px 24px; background-color: {{ $primaryColor }}; border-radius: 0;">
                                    <a class="o_text-white" href="{{ $data['link'] }}"
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