define(
  [
    'Magento_Checkout/js/view/payment/default',
    'jquery',
    'Magento_Ui/js/model/messageList',
    'ko'
  ],
  function (Component, $, messageList, ko) {
    'use strict';

    return Component.extend({
      defaults: {
        template: 'Gerencianet_Magento2/payment/openfinance',
        ofOwnerCpf: '',
        ofOwnerCnpj: '',
        documentType: '',
        ofOwnerBanking: ''
      },

      participants: ko.observableArray([]),

      initObservable: function () {
        this._super()
          .observe([
            'ofOwnerCpf',
            'ofOwnerCnpj',
            'documentType',
            'ofOwnerBanking'
          ]);
        return this;
      },

      initialize: function () {
        this._super();
        this.carregarDados();
      },

      validate: function () {
        var isValid = false;
        var message = '';

        if (this.ofOwnerCnpj().length > 0) {
          isValid = this.validarCNPJ(this.ofOwnerCnpj());
          message = 'CNPJ';
        } else if (this.ofOwnerCpf().length > 0) {
          isValid = this.validarCPF(this.ofOwnerCpf());
          message = 'CPF';
        }

        this.documentType(message);

        if (!isValid) {
          messageList.addErrorMessage({
            message: (message || 'Documento') + ' inválido'
          });
          return false;
        }

        var $form = $('#' + this.getCode() + '-form');
        return $form.validation() && $form.validation('isValid') && isValid;
      },

      getData: function () {
        return {
          method: this.item.method,
          additional_data: {
            ofOwnerCpf: this.ofOwnerCpf().replace(/[^\d]+/g, ''),
            ofOwnerCnpj: this.ofOwnerCnpj().replace(/[^\d]+/g, ''),
            documentType: this.documentType(),
            ofOwnerBanking: this.ofOwnerBanking()
          }
        };
      },

      getCode: function () {
        return this.item.method;
      },

      carregarDados: function () {
        var self = this;

        $.ajax({
          url: '/gerencianet/ajax/listparticipantsopenfinance',
          type: 'GET',
          dataType: 'json',
          success: function (response) {
            var first = Object.values(response || {})[0];
            var list = Array.isArray(first) ? first : [];

            var participantsArray = list
              .filter(function (p) { return p && (p.identificador || p.nome); })
              .map(function (participant) {
                return {
                  id: participant.identificador || '',
                  name: participant.nome || ''
                };
              });

            self.participants(participantsArray);
          },
          error: function (xhr, status, error) {
            console.error('Erro na solicitação AJAX:', error);
          }
        });
      },

      validarCPF: function (cpf) {
        var cpf;
        var i;
        var add;
        var rev;

        cpf = cpf.replace(/[^\d]+/g, '');
        if (cpf == '') return false;

        if (
          cpf.length != 11 ||
          cpf == '00000000000' ||
          cpf == '11111111111' ||
          cpf == '22222222222' ||
          cpf == '33333333333' ||
          cpf == '44444444444' ||
          cpf == '55555555555' ||
          cpf == '66666666666' ||
          cpf == '77777777777' ||
          cpf == '88888888888' ||
          cpf == '99999999999'
        ) {
          return false;
        }

        add = 0;
        for (i = 0; i < 9; i++) {
          add += parseInt(cpf.charAt(i)) * (10 - i);
        }

        rev = 11 - (add % 11);
        if (rev == 10 || rev == 11) rev = 0;
        if (rev != parseInt(cpf.charAt(9))) return false;

        add = 0;
        for (i = 0; i < 10; i++) {
          add += parseInt(cpf.charAt(i)) * (11 - i);
        }

        rev = 11 - (add % 11);
        if (rev == 10 || rev == 11) rev = 0;
        if (rev != parseInt(cpf.charAt(10))) return false;

        return true;
      },

      validarCNPJ: function (cnpj) {
        cnpj = cnpj.replace(/[^\d]+/g, '');
        if (cnpj == '') return false;
        if (cnpj.length != 14) return false;

        if (
          cnpj == '00000000000000' ||
          cnpj == '11111111111111' ||
          cnpj == '22222222222222' ||
          cnpj == '33333333333333' ||
          cnpj == '44444444444444' ||
          cnpj == '55555555555555' ||
          cnpj == '66666666666666' ||
          cnpj == '77777777777777' ||
          cnpj == '88888888888888' ||
          cnpj == '99999999999999'
        ) {
          return false;
        }

        var tamanho = cnpj.length - 2;
        var numeros = cnpj.substring(0, tamanho);
        var digitos = cnpj.substring(tamanho);
        var soma = 0;
        var pos = tamanho - 7;

        for (let i = tamanho; i >= 1; i--) {
          soma += numeros.charAt(tamanho - i) * pos--;
          if (pos < 2) pos = 9;
        }

        var resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);
        if (resultado != digitos.charAt(0)) return false;

        tamanho = tamanho + 1;
        numeros = cnpj.substring(0, tamanho);
        soma = 0;
        pos = tamanho - 7;

        for (let i = tamanho; i >= 1; i--) {
          soma += numeros.charAt(tamanho - i) * pos--;
          if (pos < 2) pos = 9;
        }

        resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);
        if (resultado != digitos.charAt(1)) return false;

        return true;
      }
    });
  }
);
