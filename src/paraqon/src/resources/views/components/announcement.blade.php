{{-- Customer Announcement Email Template --}}

@php
$uniqueId = 'email_announcement_' . uniqid();
$primaryColor = $data['primaryColor'] ?? '#103947';
@endphp

<div class="{{ $uniqueId }}">
    {{-- hero-primary-button --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-primary o_px-md o_py-xl" align="center"
                    style="background-color: {{ $primaryColor }}; padding-left: 24px; padding-right: 24px; padding-top: 64px; padding-bottom: 64px;">
                    <!--[if mso]><table width="584" cellspacing="0" cellpadding="0" border="0" role="presentation"><tbody><tr><td align="center"><![endif]-->
                    <div class="o_col-6s o_sans o_text-md o_text-white o_center"
                        style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 19px; line-height: 28px; max-width: 584px; color: #ffffff; text-align: center;">
                        <h2 class="o_heading o_mb-xxs"
                            style="font-family: Helvetica, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 4px; font-size: 30px; line-height: 39px;">
                            {{ $data['title'] }}
                        </h2>
                        <p class="o_mb-md" style="font-family: 'Crimson Text', serif; margin-top: 0; margin-bottom: 24px;">
                            {{ $data['caption'] }}
                        </p>
                        <table align="center" cellspacing="0" cellpadding="0" border="0" role="presentation">
                            <tbody>
                                <tr>
                                    <td width="300" class="o_btn o_bg-white o_br o_heading o_text" align="center"
                                        style="font-family: Helvetica, Arial, sans-serif; font-weight: bold; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; mso-padding-alt: 12px 24px; background-color: #ffffff; border-radius: 0;">
                                        <a class="o_text-primary" href="{{ $data['link'] }}"
                                            style="text-decoration: none; outline: none; color: {{ $primaryColor }}; display: block; padding: 12px 24px; mso-text-raise: 3px;">
                                            {{ $data['buttonText'] }}
                                        </a>
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

    {{-- image-full --}}
    @if(isset($data['imgSrc']) && $data['imgSrc'])
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white o_sans o_text o_text-secondary" align="center"
                    style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; background-color: #ffffff; color: #424651;">
                    <p style="margin-top: 0; margin-bottom: 0;">
                        <img class="o_img-full" src="{{ $data['imgSrc'] }}"
                            width="632" height="352" alt=""
                            style="max-width: 632px; -ms-interpolation-mode: bicubic; vertical-align: middle; border: 0; line-height: 100%; height: auto; outline: none; text-decoration: none; width: 100%;">
                    </p>
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

    {{-- content --}}
    <table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
        <tbody>
            <tr>
                <td class="o_bg-white o_px-md o_py" align="center"
                    style="background-color: #ffffff; padding-left: 24px; padding-right: 24px; padding-top: 16px; padding-bottom: 16px;">
                    <!--[if mso]><table width="584" cellspacing="0" cellpadding="0" border="0" role="presentation"><tbody><tr><td align="center"><![endif]-->
                    <div class="o_col-6s o_sans o_text o_text-secondary o_center"
                        style="font-family: Helvetica, Arial, sans-serif; margin-top: 0; margin-bottom: 0; font-size: 16px; line-height: 24px; max-width: 584px; color: #424651; text-align: center;">
                        @foreach($data['contentTexts'] as $line)
                        <p style="font-family: 'Crimson Text', serif; margin-top: 0; margin-bottom: 0;">{{ $line }}</p>
                        <br>
                        @endforeach
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