<?php
/**
 * Round function
 *
 * @package   Flagbit_MEP
 * @copyright 2017 foodspring GmbH <https://www.foodspring.com>
 * @author    Pierre Bernard <pierre.bernard@foodspring.com>
 */

class Flagbit_MEP_Model_Twig_Observer_Round
{
    /* @var Flagbit_MEP_Model_Export_Adapter_Twig */
    protected $_adapter;

    /**
     * Add a new filter to the the Twig instance
     *
     * @param Varien_Event_Observer $observer
     *
     * @return void
     */
    public function addRound($observer)
    {
        $twig = $observer->getData('twig');
        $policy = $observer->getData('policy');
        $this->_adapter = $observer->getData('adapter');
        $policy->setAllowedFilter('round');
        $filter = new Twig_SimpleFilter('round', [$this, 'round']);
        $twig->addFilter($filter);
    }

    /**
     * Round numbers with the desired precision
     *
     * @param string  $values
     * @param integer $precision
     *
     * @return string
     */
    public function round($values, $precision = 0)
    {
        $delimiter = $this->_adapter->getConfigurableDelimiter();
        $numbersArray = explode($delimiter, $values);
        $numbers = '';
        if (is_array($numbersArray)) {
            foreach ($numbersArray as &$number) {
                $number = round($number, $precision);
            }
            $numbers = implode($delimiter, $numbersArray);
        }

        return $numbers;
    }
}