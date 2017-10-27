<?php
/* Icinga Web 2 | (c) 2017 Icinga Development Team | GPLv2+ */

namespace Icinga\Forms\Config\Resource;

use DateTime;
use DateTimeZone;
use ErrorException;
use Icinga\File\Storage\TemporaryLocalFileStorage;
use Icinga\Util\TimezoneDetect;
use Icinga\Web\Form;
use Icinga\Web\Form\Validator\HttpUrlValidator;
use Icinga\Web\Form\Validator\TlsCertValidator;
use Icinga\Web\Url;

/**
 * Form class for adding/modifying HTTP(S) resources
 */
class HttpResourceForm extends Form
{
    /**
     * Not neccessarily present error handling options
     *
     * @var string[]
     */
    protected $optionalErrorHandlingOptions = array(
        'force_creation',
        'tls_server_insecure',
        'tls_server_ignore_cn',
        'tls_server_discover_rootca',
        'tls_server_accept_rootca'
    );

    /**
     * Form elements which have to be above all others, in this order
     *
     * @var string[]
     */
    protected $priorizedElements = array(
        'force_creation',
        'tls_server_insecure',
        'tls_server_ignore_cn',
        'tls_server_discover_rootca',
        'tls_server_rootca_info',
        'tls_server_accept_rootca'
    );

    public function init()
    {
        $this->setName('form_config_resource_http');
        $this->setValidatePartial(true);
    }

    public function createElements(array $formData)
    {
        $this->addElement(
            'text',
            'baseurl',
            array(
                'label'         => $this->translate('Base URL'),
                'description'   => $this->translate('http[s]://<HOST>[:<PORT>][/<BASE_LOCATION>]'),
                'required'      => true,
                'validators'    => array(new HttpUrlValidator())
            )
        );

        $this->addElement(
            'text',
            'username',
            array(
                'label'         => $this->translate('Username'),
                'description'   => $this->translate(
                    'A user with access to the above URL via HTTP basic authentication'
                )
            )
        );

        $this->addElement(
            'password',
            'password',
            array(
                'label'         => $this->translate('Password'),
                'description'   => $this->translate('The above user\'s password')
            )
        );

        $tlsClientIdentities = array(
            // TODO
        );

        if (empty($tlsClientIdentities)) {
            $this->addElement(
                'note',
                'tls_client_identities_missing',
                array(
                    'ignore'        => true,
                    'label'         => $this->translate('TLS Client Identity'),
                    'description'   => $this->translate('TLS X509 client certificate with its private key (PEM)'),
                    'escape'        => false,
                    'value'         => sprintf(
                        $this->translate(
                            'There aren\'t any TLS client identities you could choose from, but you can %sadd some%s.'
                        ),
                        sprintf(
                            '<a data-base-target="_next" href="#" title="%s" class="highlighted">', // TODO
                            $this->translate('Add TLS client identity')
                        ),
                        '</a>'
                    )
                )
            );
        } else {
            $this->addElement(
                'select',
                'tls_client_identity',
                array(
                    'label'         => $this->translate('TLS Client Identity'),
                    'description'   => $this->translate('TLS X509 client certificate with its private key (PEM)'),
                    'multiOptions'  => array_merge(
                        array('' => $this->translate('(none)')),
                        $tlsClientIdentities
                    ),
                    'value'         => ''
                )
            );
        }

        $optionalErrorHandlingOptions = array_intersect($this->optionalErrorHandlingOptions, array_keys($formData));

        if (isset($formData['tls_server_rootca_cert'])) {
            $this->addRootCaCertCache();

            $optionalErrorHandlingOptions = array_merge(
                $optionalErrorHandlingOptions,
                array('tls_server_discover_rootca', 'tls_server_accept_rootca')
            );

            $this->addRootCaInfo(array(
                'x509'      => $formData['tls_server_rootca_cert'],
                'parsed'    => openssl_x509_parse($formData['tls_server_rootca_cert']),
            ));
        }

        $this->ensureOnlyErrorHandlingOptions($optionalErrorHandlingOptions);

        if (isset($formData['tls_server_rootca_cert']) && $this->getRequest()->getMethod() === 'GET') {
            $this->getElement('tls_server_accept_rootca')->setValue(1);
        }

        return $this->priorizeElements();
    }

    /**
     * Ensure that only the given error handling options are present
     *
     * @param   string[]    $optionalErrorHandlingOptions
     *
     * @return $this
     */
    protected function ensureOnlyErrorHandlingOptions(array $optionalErrorHandlingOptions = array())
    {
        foreach (array_diff($this->optionalErrorHandlingOptions, $optionalErrorHandlingOptions) as $option) {
            $this->removeElement($option);
        }

        foreach ($optionalErrorHandlingOptions as $option) {
            $element = $this->getElement($option);

            if ($element === null) {
                switch ($option) {
                    case 'force_creation':
                        $this->addElement('checkbox', 'force_creation', array(
                            'ignore'        => true,
                            'label'         => $this->translate('Force Changes'),
                            'description'   => $this->translate(
                                'Check this box to enforce changes without connectivity validation'
                            )
                        ));
                        break;

                    case 'tls_server_insecure':
                        $this->addElement('checkbox', 'tls_server_insecure', array(
                            'label'         => $this->translate('Insecure Connection'),
                            'description'   => $this->translate(
                                'Don\'t validate the remote\'s TLS certificate chain at all'
                            )
                        ));
                        break;

                    case 'tls_server_ignore_cn':
                        $this->addElement('checkbox', 'tls_server_ignore_cn', array(
                            'label'         => $this->translate('Ignore Remote CN'),
                            'description'   => $this->translate('Ignore the remote\'s TLS certificate\'s CN')
                        ));
                        break;

                    case 'tls_server_discover_rootca':
                        $this->addElement('submit', 'tls_server_discover_rootca', array(
                            'ignore'        => true,
                            'label'         => $this->translate('Discover Root CA'),
                            'description'   => $this->translate(
                                'Discover the remote\'s TLS certificate\'s root CA'
                                    . ' (makes sense only in case of an isolated PKI)'
                            )
                        ));
                        break;

                    case 'tls_server_accept_rootca':
                        $this->addElement('checkbox', 'tls_server_accept_rootca', array(
                            'ignore'        => true,
                            'label'         => $this->translate('Accept the remote\'s root CA'),
                            'description'   => $this->translate('Trust the remote\'s TLS certificate\'s root CA')
                        ));
                        break;
                }
            }
        }

        return $this;
    }

    /**
     * Add form element with the given TLS root CA certificate's info
     *
     * @param   array   $cert
     */
    protected function addRootCaInfo($cert)
    {
        $timezoneDetect = new TimezoneDetect();
        $timeZone = new DateTimeZone(
            $timezoneDetect->success() ? $timezoneDetect->getTimezoneName() : date_default_timezone_get()
        );
        $view = $this->getView();

        $subject = array();
        foreach ($cert['parsed']['subject'] as $key => $value) {
            $subject[] = $view->escape("$key = " . var_export($value, true));
        }

        $this->addElement(
            'note',
            'tls_server_rootca_info',
            array(
                'ignore'    => true,
                'escape'    => false,
                'label'     => $this->translate('Root CA'),
                'value'     => sprintf(
                    '<table class="name-value-list">' . str_repeat('<tr><td>%s</td><td>%s</td></tr>', 5) . '</table>',
                    $view->escape($this->translate('Subject', 'x509.certificate')),
                    implode('<br>', $subject),
                    $view->escape($this->translate('Valid from', 'x509.certificate')),
                    $view->escape(
                        DateTime::createFromFormat('U', $cert['parsed']['validFrom_time_t'])
                            ->setTimezone($timeZone)
                            ->format(DateTime::ISO8601)
                    ),
                    $view->escape($this->translate('Valid until', 'x509.certificate')),
                    $view->escape(
                        DateTime::createFromFormat('U', $cert['parsed']['validTo_time_t'])
                            ->setTimezone($timeZone)
                            ->format(DateTime::ISO8601)
                    ),
                    $view->escape($this->translate('SHA256 fingerprint', 'x509.certificate')),
                    $view->escape(
                        implode(' ', str_split(strtoupper(openssl_x509_fingerprint($cert['x509'], 'sha256')), 2))
                    ),
                    $view->escape($this->translate('SHA1 fingerprint', 'x509.certificate')),
                    $view->escape(
                        implode(' ', str_split(strtoupper(openssl_x509_fingerprint($cert['x509'], 'sha1')), 2))
                    )
                )
            )
        );
    }

    /**
     * Add and return form element for the discovered TLS root CA certificate
     *
     * @return \Zend_Form_Element_Hidden
     */
    protected function addRootCaCertCache()
    {
        $element = $this->getElement('tls_server_rootca_cert');
        if ($element === null) {
            $this->addElement(
                'hidden',
                'tls_server_rootca_cert',
                array('validators' => array(new TlsCertValidator()))
            );

            return $this->getElement('tls_server_rootca_cert');
        }

        return $element;
    }

    /**
     * Reorder form elements as needed
     */
    protected function priorizeElements()
    {
        $priorizedElements = array();
        foreach ($this->priorizedElements as $priorizedElement) {
            $element = $this->getElement($priorizedElement);
            if ($element !== null) {
                $element->setOrder(null);
                $priorizedElements[] = $element;
            }
        }

        $nextOrder = -1;
        foreach ($priorizedElements as $priorizedElement) {
            /** @var \Zend_Form_Element $priorizedElement */
            $priorizedElement->setOrder(++$nextOrder);
        }

        foreach ($this->getElements() as $name => $element) {
            $this->_order[$name] = $element->getOrder();
        }

        return $this;
    }

    public function isValidPartial(array $formData)
    {
        if (! parent::isValidPartial($formData)) {
            return false;
        }

        $result = $this->isEndpointValid();
        $this->priorizeElements();
        return $result;
    }

    public function isValid($formData)
    {
        if (! parent::isValid($formData)) {
            return false;
        }

        $result = $this->isEndpointValid();
        $this->priorizeElements();
        return $result;
    }

    /**
     * Return whether the configured endpoint is valid
     *
     * @return bool
     */
    protected function isEndpointValid()
    {
        if ($this->isElementChecked('force_creation') || $this->getValue('baseurl') === null) {
            return true;
        }

        if (Url::fromPath($this->getValue('baseurl'))->getScheme() === 'https') {
            if (! $this->probeInsecureTlsConnection()) {
                $this->ensureOnlyErrorHandlingOptions(array('force_creation'));
                return false;
            }

            if ($this->isElementChecked('tls_server_insecure')) {
                return true;
            }

            if ($this->isElementChecked('tls_server_discover_rootca')) {
                $this->removeElement('tls_server_rootca_cert');

                $certs = $this->fetchServerTlsCertChain();
                if ($certs === false) {
                    return false;
                }

                if ($certs['leaf']['parsed']['subject']['CN'] === $certs['leaf']['parsed']['issuer']['CN']) {
                    $this->error($this->translate('The remote didn\'t provide any non-self-signed TLS certificate'));
                    return false;
                }

                if (! isset($certs['root'])) {
                    $this->error($this->translate('The remote didn\'t provide any root CA certificate'));
                    return false;
                }

                $this->ensureOnlyErrorHandlingOptions(array(
                    'force_creation',
                    'tls_server_insecure',
                    'tls_server_ignore_cn',
                    'tls_server_discover_rootca',
                    'tls_server_accept_rootca'
                ));
                $this->addRootCaInfo($certs['root']);
                $this->addRootCaCertCache()->setValue($certs['root']['x509']);
                return false;
            }

            $rootCaCert = $this->getValue('tls_server_rootca_cert');

            if ($rootCaCert !== null && $this->isElementChecked('tls_server_accept_rootca')) {
                $temporaryLocalFileStorage = new TemporaryLocalFileStorage();
                $temporaryLocalFileStorage->create('rootca.pem', $rootCaCert);
                $rootCaPath = $temporaryLocalFileStorage->resolvePath('rootca.pem');
            } else {
                $rootCaPath = null;
            }

            if ($rootCaCert !== null) {
                $this->addRootCaInfo(array(
                    'x509'      => $rootCaCert,
                    'parsed'    => openssl_x509_parse($rootCaCert),
                ));
            }

            if (! $this->probeSecureTlsConnection($this->isElementChecked('tls_server_ignore_cn'), $rootCaPath)) {
                $optionalErrorHandlingOptions = array(
                    'force_creation',
                    'tls_server_insecure',
                    'tls_server_ignore_cn',
                    'tls_server_discover_rootca'
                );
                if ($rootCaCert !== null) {
                    $optionalErrorHandlingOptions[] = 'tls_server_accept_rootca';
                }

                $this->ensureOnlyErrorHandlingOptions($optionalErrorHandlingOptions);
                return false;
            }

            $this->removeElement('force_creation');
            $this->removeElement('tls_server_insecure');
        } else {
            if (! $this->probeTcpConnection()) {
                $this->ensureOnlyErrorHandlingOptions(array('force_creation'));
                return false;
            }

            $this->ensureOnlyErrorHandlingOptions(array());
        }

        return true;
    }

    /**
     * Return whether a TCP connection to the remote is possible and eventually add form errors
     *
     * @return bool
     */
    protected function probeTcpConnection()
    {
        try {
            fclose(stream_socket_client('tcp://' . $this->getTcpEndpoint()));
        } catch (ErrorException $element) {
            $this->error($element->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Return whether an insecure TLS connection to the remote is possible and eventually add form errors
     *
     * @return bool
     */
    protected function probeInsecureTlsConnection()
    {
        try {
            fclose($this->createTlsStream(stream_context_create($this->includeTlsClientIdentity(array('ssl' => array(
                'verify_peer'       => false,
                'verify_peer_name'  => false
            ))))));
        } catch (ErrorException $element) {
            $this->error($element->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Return whether a secure TLS connection to the remote is possible and eventually add form errors
     *
     * @param   bool    $ignoreCn       Whether to ignore the remote's TLS certificate's CN
     * @param   string  $rootCaPath     Path to custom root CA to use
     *
     * @return  bool
     */
    protected function probeSecureTlsConnection($ignoreCn = false, $rootCaPath = null)
    {
        $options = array();
        if ($rootCaPath !== null) {
            $options['ssl']['cafile'] = $rootCaPath;
        }
        if ($ignoreCn) {
            $options['ssl']['verify_peer_name'] = false;
        }

        try {
            fclose($this->createTlsStream(stream_context_create($this->includeTlsClientIdentity($options))));
        } catch (ErrorException $element) {
            $this->error($element->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Add the TLS client certificate to use (if any) to the given stream context options and return them
     *
     * @param   array   $contextOptions
     *
     * @return  array
     */
    protected function includeTlsClientIdentity(array $contextOptions)
    {
        if ($this->getValue('tls_client_identity') !== null) {
            $contextOptions['ssl']['local_cert'] = null; // TODO
        }
        
        return $contextOptions;
    }

    /**
     * Create a TLS stream to the remote with the the given stream context 
     *
     * @param   resource    $context
     *
     * @return  resource
     */
    protected function createTlsStream($context)
    {
        return stream_socket_client(
            'tls://' . $this->getTcpEndpoint(),
            $errno,
            $errstr,
            ini_get('default_socket_timeout'),
            STREAM_CLIENT_CONNECT,
            $context
        );
    }

    /**
     * Get <HOST>:<PORT>
     *
     * @return string
     */
    protected function getTcpEndpoint()
    {
        $baseurl = Url::fromPath($this->getValue('baseurl'));
        $port = $baseurl->getPort();

        return $baseurl->getHost() . ':' . ($port === null ? '443' : $port);
    }

    /**
     * Return whether the given element is present and checked
     *
     * @param   string  $name
     *
     * @return  bool
     */
    protected function isElementChecked($name)
    {
        /** @var \Zend_Form_Element_Checkbox|\Zend_Form_Element_Submit $element */
        $element = $this->getElement($name);
        return $element !== null && $element->isChecked();
    }

    /**
     * Try to fetch the remote's TLS certificate chain
     *
     * @return array|false
     */
    protected function fetchServerTlsCertChain()
    {
        $context = stream_context_create($this->includeTlsClientIdentity(array('ssl' => array(
            'verify_peer'               => false,
            'verify_peer_name'          => false,
            'capture_peer_cert_chain'   => true
        ))));

        try {
            fclose($this->createTlsStream($context));
        } catch (ErrorException $e) {
            $this->error($e->getMessage());
            return false;
        }

        $params = stream_context_get_params($context);
        $rawChain = $params['options']['ssl']['peer_certificate_chain'];
        $chain = array('leaf' => array('x509' => null));

        openssl_x509_export(reset($rawChain), $chain['leaf']['x509']);

        if (count($rawChain) > 1) {
            $chain['root'] = array('x509' => null);
            openssl_x509_export(end($rawChain), $chain['root']['x509']);
        }

        foreach ($chain as & $cert) {
            $cert['parsed'] = openssl_x509_parse($cert['x509']);
        }

        if (isset($chain['root'])
            && $chain['root']['parsed']['subject']['CN'] !== $chain['root']['parsed']['issuer']['CN']
        ) {
            unset($chain['root']);
        }

        return $chain;
    }
}
