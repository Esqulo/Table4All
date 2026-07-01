<x-mail::message>
# Você foi convidado!

**{{ $restaurantName }}** convidou você para trabalhar como garçom no **Table4All**.

Clique no botão abaixo para criar sua conta e aceitar o convite:

<x-mail::button :url="$acceptUrl">
Aceitar convite
</x-mail::button>

Este convite expira em **7 dias**.

Caso você não reconheça este convite, ignore este e-mail com segurança.

Atenciosamente,
Equipe Table4All
</x-mail::message>
