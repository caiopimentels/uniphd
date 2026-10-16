<?php

{
  "etapa_processo_boleto": "efetivacao",
  "beneficiario": {
    "id_beneficiario": "150000052061"
  },
  "dado_boleto": {
    "descricao_instrumento_cobranca": "boleto_pix",
    "tipo_boleto": "a vista",
    "codigo_carteira": "109",
    "forma_envio": "impressao",
    "codigo_especie": "01",
    "valor_abatimento": 0.00,
    "data_emissao": "0000-00-00",
    "codigo_tipo_vencimento": 03,
    "valor_total_titulo": "1234.56",
    "pagador": {
      "pessoa": {
        "nome_pessoa": "João da Silva",
        "tipo_pessoa": {
          "codigo_tipo_pessoa": "F",
          "numero_cadastro_pessoa_fisica": "12345678901"
        }
      },
      "endereco": {
        "nome_logradouro": "Rua das Flores",
        "nome_bairro": "Centro",
        "nome_cidade": "São Paulo",
        "sigla_UF": "SP",
        "numero_CEP": "01310100"
      }
    },
    "dados_individuais_boleto": [
      {
        "numero_nosso_numero": "12345678",
        "data_vencimento": "2026-12-31",
        "valor_titulo": "1234.56"
      }
    ]
    "juros":[
          "codigo_tipo_juros": 90,
          "percentual_juros": 00.00
          "data_juros": "0000-00-00"
    ]
    "multa":[
          "codigo_tipo_multa": 02,
          "percentual_multa": 00.00
          "data_multa": "0000-00-00"
    ]
    "desconto":[
          "codigo_tipo_desconto": 00,
          "descontos":[
              "valor_desconto": 00.00                     
              "percentual_desconto": 00.00
              "data_desconto": "0000-00-00"
          ]
    ]
    "recebimento_divergente":

  }
}

/*
codigo_especie:
01 - DM - Duplicata de Venda Mercantil
02 - NP - Nota Promissória
03 - NS - Nota de Seguro
04 - ME - Mensalidade Escolar
05 - RC - Recibo
06 - CT - Contrato
08 - DS - Duplicata de Prestação de Serviços Original
09 - LC - Letra de Câmbio
15 - DD - Documento de Dívida
16 - EC - Encargos CondominaisEncargos Condominais
17 - FS - Fatura de Serviço
18 - BDP - Boleto Proposta
33 - BDA - Boleto Depósito Aporte (procure seu gerente para uso dessa espécie)
88 - CBI - Cédula de Crédito Bancário por Indicação
89 - CC - Contrato de Câmbio
90 - CCB - Cédula de Crédito Bancário
91 - CD - Confissão de Dívida
92 - CH - Cheque
93 - CM - Contrato de Mútuo
94 - CPS - Conta de Prestação de Serviços
95 - DMI - Duplicata de Venda Mercantil por Indicação
96 - DSI - Duplicata de Prestação de Serviços - Original Por Indicação
97 - RA - Recibo de Aluguel (PJ)
98 - TA - Termo de acordo. Ex: acao trabalhista
99 - DV - Diversos

juros:
'05' - Quando não se deseja cobrar juros caso o pagamento seja feito após o vencimento (isento)
'90' - Percentual mensal (utilizando parâmetros do cadastro de beneficiário para dias úteis ou corridos)
'91' - Percentual diário (utilizando parâmetros do cadastro de beneficiário para dias úteis ou corridos)
'92' - Percentual anual (utilizando parâmetros do cadastro de beneficiário para dias úteis ou corridos)
'93' - Valor diário (utilizando parâmetros do cadastro de beneficiário para dias úteis ou corridos)

multa:
'01' - Quando se deseja cobrar um valor fixo de multa após o vencimento.
'02' - Quando se deseja cobrar um percentual do valor do título de multa após o vencimento.
'03' - Quando não se deseja cobrar multa caso o pagamento seja feito após o vencimento (isento)

desconto:
'00' - Quando não houver condição de desconto (sem desconto).
'01' - Quando o desconto for um valor fixo se o título for pago até a data informada (data_desconto).
'02' - Quando o desconto for um percentual (mensal) do valor do título e for pago até a data informada (data_desconto).
'90' - Percentual por antecipação (utilizando parâmetros do cadastro de beneficiário para dias úteis ou corridos).
'91' - Valor por antecipação (utilizando parâmetros do cadastro de beneficiário para dias úteis ou corridos).
*/

?>