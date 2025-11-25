<div style="margin: 0 auto; max-width: 600px">
  <table style="width: 100%">
    <tbody>
      <tr style="height: 12px"></tr>
      <tr>
        <td style="font-size: 16px; text-align: center">
          <p>{{ $data["caption"] }}</p>
          <p style="line-height: 24px">
            @foreach ($data['addressLines'] as $line)
            {{ $line }}<br />
            @endforeach
          </p>
          <p>
            @foreach ($data['links'] as $index => $item)
            <a href="{{ $item['url'] }}">{{ $item["text"] }}</a>
            @if ($index + 1 != count($data['links']))
            <span class="o_hide-xs">&nbsp; • &nbsp;</span>
            @endif @endforeach
          </p>
        </td>
      </tr>
    </tbody>
  </table>
</div>