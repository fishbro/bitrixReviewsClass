<?php
namespace Local\FishBro;

use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Diag\Debug;

Loader::includeModule('iblock');

final class Reviews
{
	const IBLOCK_ID = 2; //ID инфоблока отзывов
	const REVIEW_PROPS = array( //Код свойств содержащих рейтинг
		'RATING',
		'PRICING',
		'COMFORT'
	);
	const OBJECT_ID_PROPERTY_CODE = 'OBJECT_ID'; //Код свойства привязки к элементу
	const OVERALL_PROPERTY_CODE = 'OVERALL_RATING'; //Код свойства этогового рейтинга
	const COUNT_PROPERTY_CODE = 'REVIEWS_COUNT'; //Код количества отзывов

	public static function registerEvents(){
		EventManager::getInstance()->addEventHandler('iblock', 'OnAfterIBlockElementAdd', [new self(), 'reviewTrigger']);
		EventManager::getInstance()->addEventHandler('iblock', 'OnAfterIBlockElementUpdate', [new self(), 'reviewTrigger']);
		EventManager::getInstance()->addEventHandler('iblock', 'OnAfterIBlockElementAdd', [new self(), 'objectsTrigger']);
		EventManager::getInstance()->addEventHandler('iblock', 'OnAfterIBlockElementUpdate', [new self(), 'objectsTrigger']);
		EventManager::getInstance()->addEventHandler('iblock', 'OnBeforeIBlockElementDelete', [new self(), 'objectsTriggerRemove']);
	}

	public static function reviewTrigger($arFields){
		if($arFields['IBLOCK_ID'] == self::IBLOCK_ID){
			self::processReview($arFields);
		}
	}

	private static function processReview($arFields){
		$reviewProps = self::REVIEW_PROPS;
		$id = $arFields['ID'];
		$reviewData = Reviews::getReviewDetails($id);
		$rating_props = array_filter($reviewData['PROPERTIES'], function($prop) use ($reviewProps){
			return in_array($prop['CODE'], $reviewProps) && $prop['VALUE'];
		});
		$rating_values = array_map(function($prop){
			return $prop['VALUE'];
		}, $rating_props);
		$overall_rating = self::calculateOverall($rating_values);

		if($overall_rating > 0){
			\CIBlockElement::SetPropertyValuesEx($id, self::IBLOCK_ID, array(self::OVERALL_PROPERTY_CODE => $overall_rating));
		}
	}

	public static function objectsTrigger($arFields){
		if($arFields['IBLOCK_ID'] == self::IBLOCK_ID){
			$reviewData = Reviews::getReviewDetails($arFields['ID']);

			self::processObject($reviewData);
		}
	}

	public static function objectsTriggerRemove($id){
		$res = \CIBlockElement::GetByID($id);
		if($arFields = $res->GetNext()){
			if($arFields['IBLOCK_ID'] == self::IBLOCK_ID){
				$reviewData = Reviews::getReviewDetails($arFields['ID']);
				Debug::dumpToFile($reviewData);

				self::processObject($reviewData, true);
			}
		}
	}

	private static function processObject($arFields, $is_remove = false){
		$review_id = $arFields['ID'];
		$object_id = $arFields['PROPERTIES'][self::OBJECT_ID_PROPERTY_CODE]['VALUE'];

		$arSelect = array('ID', 'IBLOCK_ID', 'PROPERTY_*');
		$arFilter = Array(
			"IBLOCK_ID" => self::IBLOCK_ID,
			"ACTIVE" => "Y",
			"PROPERTY_".self::OBJECT_ID_PROPERTY_CODE => $object_id
		);
		if($is_remove) $arFilter['!ID'] = $review_id;

		$ratings = array();
		$count = 0;
		$res = \CIBlockElement::GetList(Array(), $arFilter, false, false, $arSelect);
		while($ob = $res->GetNextElement()){
			$arFields = $ob->GetFields();
			$arFields['PROPERTIES'] = $ob->GetProperties();

			foreach(self::REVIEW_PROPS as $prop_code){
				if($arFields['PROPERTIES'][$prop_code]['VALUE']){
					$ratings[$prop_code][] = $arFields['PROPERTIES'][$prop_code]['VALUE'];
				}
			}

			$count++;
		}

		$ratings = array_map(function($rating_type){
			return self::calculateOverall($rating_type);
		}, $ratings);

		$overall = self::calculateOverall($ratings);

		self::updateProduct($object_id, $count, $ratings, $overall);
	}

	private static function updateProduct($id, $count, $ratings, $overall){
		\CIBlockElement::SetPropertyValuesEx($id, false, array(self::OVERALL_PROPERTY_CODE => $overall));
		\CIBlockElement::SetPropertyValuesEx($id, false, array(self::COUNT_PROPERTY_CODE => $count));
		foreach (self::REVIEW_PROPS as $property_code){
			\CIBlockElement::SetPropertyValuesEx($id, false, array($property_code => $ratings[$property_code]));
		}
	}

	private static function calculateOverall($values){
		return round(array_sum($values) / count($values), 1);
	}

	public static function getReviewDetails($id): array {
		Loader::includeModule('iblock');

		$arSelect = array('ID', 'IBLOCK_ID', 'PROPERTY_*');
		$arFilter = Array(
			"IBLOCK_ID" => self::IBLOCK_ID,
			"ID" => $id
		);

		$arResult = array();
		$res = \CIBlockElement::GetList(Array(), $arFilter, false, false, $arSelect);
		if($ob = $res->GetNextElement()){
			$arResult = $ob->GetFields();
			$arResult['PROPERTIES'] = $ob->GetProperties();
		}

		return $arResult;
	}
}