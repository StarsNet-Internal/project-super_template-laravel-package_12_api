<div style="margin: 0px auto; max-width: 600px; background: white">
  <hr style="margin: 0 auto; max-width: 600px" />
  <table style="width: 100%">
    <tbody>
      <tr style="height: 12px"></tr>
      <tr>
        <td style="text-align: center; padding: 10px 25px">
          <h1 style="margin: 0; font-size: 24px; font-weight: bold">
            {{ $data["title"] }}
          </h1>
        </td>
      </tr>
      <tr>
        <td style="text-align: center">
          <div style="position: relative; display: inline-block; width: 100%">
            <img
              src="{{ $data['imgSrc'] }}"
              width="100%"
              style="display: block"
            />
            <div
              style="
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4%;
                background: linear-gradient(
                  to bottom,
                  white 0%,
                  rgba(255, 255, 255, 0) 100%
                );
                pointer-events: none;
              "
            ></div>
            <div
              style="
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                height: 4%;
                background: linear-gradient(
                  to top,
                  white 0%,
                  rgba(255, 255, 255, 0) 100%
                );
                pointer-events: none;
              "
            ></div>
            <div
              style="
                position: absolute;
                top: 0;
                left: 0;
                bottom: 0;
                width: 4%;
                background: linear-gradient(
                  to right,
                  white 0%,
                  rgba(255, 255, 255, 0) 100%
                );
                pointer-events: none;
              "
            ></div>
            <div
              style="
                position: absolute;
                top: 0;
                right: 0;
                bottom: 0;
                width: 4%;
                background: linear-gradient(
                  to left,
                  white 0%,
                  rgba(255, 255, 255, 0) 100%
                );
                pointer-events: none;
              "
            ></div>
          </div>
        </td>
      </tr>
      <tr>
        <td style="padding: 10px 25px">
          <div style="font-size: 16px">
            <p style="margin: 0; text-align: justify">
              {{ $data["caption"] }}
            </p>
          </div>
        </td>
      </tr>
      <tr style="height: 12px"></tr>
    </tbody>
  </table>
  <hr style="margin: 0 auto; max-width: 600px" />
</div>
