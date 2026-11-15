<x-mail::message>
# Nova solicitação de acesso

Um estabelecimento pediu para usar o **Table4All** pela página de contato.

<x-mail::table>
| | |
|:--|:--|
| **Estabelecimento** | {{ $request->business_name }} |
| **E-mail** | {{ $request->contact_email }} |
| **Telefone** | {{ $request->phone ?: '—' }} |
| **Cidade** | {{ $request->city ?: '—' }} |
| **Mesas** | {{ $request->tables ?: '—' }} |
</x-mail::table>

@if ($request->message)
**Mensagem:**

{{ $request->message }}
@endif

Responder este e-mail responde direto para {{ $request->contact_email }}.

<x-mail::button :url="$panelUrl">
Abrir painel de solicitações
</x-mail::button>

Table4All
</x-mail::message>
