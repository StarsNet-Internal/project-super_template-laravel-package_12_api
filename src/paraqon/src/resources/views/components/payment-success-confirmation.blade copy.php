<div style="margin: 0 auto; max-width: 600px">
  <table style="width: 100%; background-color: #103947">
    <tbody>
      <tr style="height: 24px"></tr>
      <tr>
        <td style="text-align: center; padding: 0 12px">
          <h1 style="color: white">{{ $data["title"] }}</h1>
        </td>
      </tr>
      <tr>
        <td style="text-align: center">
          <h2 style="font-size: 24px; font-weight: normal; color: white">
            {{ $data["caption"] }}
          </h2>
        </td>
      </tr>
     <tr style="height: 24px"></tr>
    </tbody>
  </table>

  <table style="width: 100%; background-color: white">
    <tbody>
      <tr style="height: 24px"></tr>
      <tr>
        <td style="text-align: center">
          <h1 style="font-size: 24px; font-weight: normal; color: black">
             {{ $data["orderName"] }}
          </h1>
        </td>
      </tr>
      <tr>
        <td style="text-align: center">
          <a
            href="{{ $data['link'] }}"
            style="
              background: #103947;
              color: white;
              font-size: 20px;
              line-height: 40px;
              text-decoration: none;
              padding: 16px 100px;
            "
          >
            {{ $data["buttonText"] }}
          </a>
        </td>
      </tr>
      <tr style="height: 24px"></tr>
      <tr>
        <td style="text-align: center">
          <h1 style="font-size: 24px; font-weight: bold">
            {{ $data["orderTitle"] }}
          </h1>
          <p style="font-size: 16px; color: grey">
            {{ $data["orderSubtitle"] }}
          </p>
        </td>
      </tr>
      <tr style="height: 24px"></tr>
      <tr>
        <td style="text-align: center">
          <p style="font-size: 16px; color: black">{{ $data['orderSummaryText'] }}</p>
        </td>
      </tr>
      <tr>
        <td style="text-align: center; padding: 0 4px">
          <hr
            style="margin: 0 auto; max-width: 600px; border-top: 0px solid gray"
          />
        </td>
      </tr>
      @foreach($data['productItems'] as $item)
      <tr style="height: 12px"></tr>
      <tr>
        <td style="text-align: center; padding: 0 4px">
          <img src="{{ $item['imageUrl'] }}" width="200" />
          <p style="font-weight: bold; padding: 0 12px">
            {{ $item["name"] }}
          </p>
          <p style="padding: 0 12px" {{ $item['caption'] }}</p>
          <p style="font-size: 20px; font-weight: bold">{{ $item['price'] }}</p>
        </td>
      </tr>
      <tr style="height: 12px"></tr>
      <tr>
        <td style="text-align: center; padding: 0 4px">
          <hr
            style="margin: 0 auto; max-width: 600px; border-top: 0px solid gray"
          />
        </td>
      </tr>
      @endforeach
      <tr>
        <td>
          <table style="text-align: right; width: 100%">
            <tr>
              <td style="padding: 24px"></td>
              <td style="text-align: right">{{ $data['subtotalText'] }}</td>
              <td style="text-align: right; width: 40%">{{ $data['subtotalValue'] }}</td>
            </tr>
            <tr>
              <td style="padding: 24px"></td>
              <td style="text-align: right">{{ $data['shippingText'] }}</td>
              <td style="text-align: right; width: 40%">{{ $data['shippingValue'] }}</td>
            </tr>
            <tr>
              <td style="padding: 24px"></td>
              <td style="text-align: right; font-weight: bold">{{ $data['totalText'] }}</td>
              <td style="text-align: right; font-weight: bold">{{ $data['totalValue'] }} </td>
            </tr>
          </table>
        </td>
      </tr>
    </tbody>
  </table>
  <hr style="margin: 0 auto; max-width: 600px" />
</div>
