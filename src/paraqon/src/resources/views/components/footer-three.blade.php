<table width="100%" cellspacing="0" cellpadding="0" border="0" role="presentation">
    <tbody>
        <tr>
            <td class="o_bg-dark o_px-md o_py-lg" align="center"
                style="background-color: #103947;padding-left: 24px;padding-right: 24px;padding-top: 32px;padding-bottom: 32px;">
                <div class="o_col-6s o_sans o_text-xs o_text-dark_light"
                    style="font-family: Crimson Text;margin-top: 0px;margin-bottom: 0px;font-size: 14px;line-height: 21px;max-width: 584px;color: #ffffff;">
                    <p class="o_mb"
                        style="margin-top: 0px;margin-bottom: 16px; font-family: Crimson Text; font-size:16px;">
                        {{ $data['caption'] }}
                    </p>
                    <p class="o_mb" style="margin-top: 0px;margin-bottom: 16px;">
                        @foreach ($data['addressLines'] as $line)
                    <div style="font-family: Crimson Text; font-size:16px;">
                        {{ $line }}<br>
                    </div>
                    @endforeach
                    </p>
                    <p style="margin-top: 0px;margin-bottom: 0px;">
                        @foreach ($data['links'] as $index => $item)
                    <div style="display:inline;">
                        <a class="o_text-dark_light o_underline" href="{{ $item['url'] }}"
                            style="font-family: Crimson Text;font-size:16px;text-decoration: underline;outline: none;color: #ffffff;">
                            {{ $item['text'] }}</a>
                        @if ($index + 1 != count($data['links']))
                        <span class="o_hide-xs">&nbsp; • &nbsp;</span>
                        @endif
                        <br class="o_hide-lg" style="display: none;font-size: 0;max-height: 0;width: 0;line-height: 0;overflow: hidden; mso-hide: all;visibility: hidden;">
                    </div>
                    @endforeach
                    </p>
                </div>
            </td>
        </tr>
    </tbody>
</table>

<style>
    a {
        text-decoration: none;
        outline: none;
    }

    @font-face {
        font-family: 'Montserrat';
        src: url('https://fonts.googleapis.com/css2?family=Montserrat&display=swap') format('truetype');
        font-weight: normal;
        font-style: normal;
    }

    @media (max-width: 649px) {
        .o_col-full {
            max-width: 100% !important;
        }

        .o_col-half {
            max-width: 50% !important;
        }

        .o_hide-lg {
            display: inline-block !important;
            font-size: inherit !important;
            max-height: none !important;
            line-height: inherit !important;
            overflow: visible !important;
            width: auto !important;
            visibility: visible !important;
        }

        .o_hide-xs,
        .o_hide-xs.o_col_i {
            display: none !important;
            font-size: 0 !important;
            max-height: 0 !important;
            width: 0 !important;
            line-height: 0 !important;
            overflow: hidden !important;
            visibility: hidden !important;
            height: 0 !important;
        }

        .o_xs-center {
            text-align: center !important;
        }

        .o_xs-left {
            text-align: left !important;
        }

        .o_xs-right {
            text-align: left !important;
        }

        table.o_xs-left {
            margin-left: 0 !important;
            margin-right: auto !important;
            float: none !important;
        }

        table.o_xs-right {
            margin-left: auto !important;
            margin-right: 0 !important;
            float: none !important;
        }

        table.o_xs-center {
            margin-left: auto !important;
            margin-right: auto !important;
            float: none !important;
        }

        h1.o_heading {
            font-size: 32px !important;
            line-height: 41px !important;
        }

        h2.o_heading {
            font-size: 26px !important;
            line-height: 37px !important;
        }

        h3.o_heading {
            font-size: 20px !important;
            line-height: 30px !important;
        }

        .o_xs-py-md {
            padding-top: 24px !important;
            padding-bottom: 24px !important;
        }

        .o_xs-pt-xs {
            padding-top: 8px !important;
        }

        .o_xs-pb-xs {
            padding-bottom: 8px !important;
        }
    }

    @media screen {
        .o_heading {
            font-family: "Montserrat", sans-serif !important;
        }

        .o_heading,
        strong,
        b {
            font-weight: 700 !important;
        }
    }
</style>