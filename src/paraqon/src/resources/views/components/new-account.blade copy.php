<div style="margin: 0px auto; max-width: 600px; background: white">
  <hr style="margin: 0 auto; max-width: 600px" />
  <table style="width: 100%">
    <tbody>
      <tr>
        <td style="padding: 10px 25px">
          <h1 style="margin: 0; font-size: 24px; font-weight: bold">
            {{ $data["title"] }}
          </h1>
        </td>
      </tr>
      <tr>
        <td style="padding: 10px 25px">
          <div style="font-size: 16px">
            <p style="margin: 0">{{ $data["caption"] }}</p>
          </div>
        </td>
      </tr>
      <tr>
        <td style="padding: 10px 25px">
          <div style="font-size: 16px">
            <p style="margin: 0">
              Email:
              <span style="font-weight: bold">{{ $data["email"] }}</span>
            </p>
            <p></p>
            <p style="margin: 0">
              Phone:
              <span style="font-weight: bold">{{ $data["phone"] }}</span>
            </p>
            <p></p>
            <p style="margin: 0">
              {{ $data["passwordLabelText"] }}:
              <span style="font-weight: bold">{{ $data["password"] }}</span>
            </p>
          </div>
        </td>
      </tr>
      <tr style="height: 80px">
        <td style="text-align: center">
          <a
            href="{{ $data['link'] }}"
            style="
              background: #103947;
              color: white;
              font-size: 16px;
              text-decoration: none;
              padding: 16px;
            "
          >
            {{ $data["buttonText"] }}
          </a>
        </td>
      </tr>
    </tbody>
  </table>
  <hr style="margin: 0 auto; max-width: 600px" />
</div>
