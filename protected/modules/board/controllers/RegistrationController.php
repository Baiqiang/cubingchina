<?php
class RegistrationController extends AdminController {
	public function actionIndex() {
		$model = new Registration();
		$model->unsetAttributes();
		$model->attributes = $this->aRequest('Registration');
		if ($model->competition_id === null) {
			$model->competition_id = 0;
		}
		if ($this->user->isOrganizer() && $model->competition && !isset($model->competition->organizers[$this->user->id])) {
			Yii::app()->user->setFlash('danger', '权限不足！');
			$this->redirect(array('/board/registration/index'));
		}
		$this->render('index', array(
			'model'=>$model,
		));
	}

	public function actionExport() {
		$id = $this->iGet('id');
		$model = Competition::model()->findByPk($id);
		if ($model === null) {
			$this->redirect(Yii::app()->request->urlReferrer);
		}
		if ($this->user->isOrganizer() && !isset($model->organizers[$this->user->id])) {
			Yii::app()->user->setFlash('danger', '权限不足！');
			$this->redirect(array('/board/registration/index'));
		}
		$model->formatEvents();
		if (isset($_POST['event'])) {
			$this->export($model, $this->aPost('event'), $this->iPost('all'), $this->iPost('xlsx'), $this->iPost('extra'), $this->sPost('order'));
		}
		$exportFormsts = Events::getAllExportFormats();
		$this->render('export', array(
			'model'=>$model,
			'competition'=>$model,
			'exportFormsts'=>$exportFormsts,
		));
	}

	public function actionScoreCard() {
		$id = $this->iGet('id');
		$model = Competition::model()->findByPk($id);
		if ($model === null) {
			$this->redirect(Yii::app()->request->urlReferrer);
		}
		if ($this->user->isOrganizer() && !isset($model->organizers[$this->user->id])) {
			Yii::app()->user->setFlash('danger', '权限不足！');
			$this->redirect(array('/board/registration/index'));
		}
		if (isset($_POST['order'])) {
			$this->exportScoreCard($model, $this->iPost('all'), $this->sPost('order'));
		}
		$this->render('scoreCard', array(
			'model'=>$model,
			'competition'=>$model,
		));
	}

	public function export($competition, $exportFormsts, $all = false, $xlsx = false, $extra = false, $order = 'date') {
		$registrations = $this->getRegistrations($competition, $all, $order);
		$template = PHPExcel_IOFactory::load(Yii::getPathOfAlias('application.data.results') . '.xls');
		$export = new PHPExcel();
		$export->getProperties()
			->setCreator(Yii::app()->params->author)
			->setLastModifiedBy(Yii::app()->params->author)
			->setTitle($competition->wca_competition_id ?: $competition->name)
			->setSubject($competition->name);
		$export->removeSheetByIndex(0);
		//注册页
		$sheet = $template->getSheet(0);
		$sheet->setCellValue('A1', $competition->wca_competition_id ?: $competition->name);
		$events = $competition->getRegistrationEvents();
		$col = 'J';
		$cubecompsEvents = array(
			'333'=>'3x3',
			'444'=>'4x4',
			'555'=>'5x5',
			'666'=>'6x6',
			'777'=>'7x7',
			'222'=>'2x2',
			'333bf'=>'333bld',
			'333fm'=>'fmc',
			'minx'=>'mega',
			'pyram'=>'pyra',
			'444bf'=>'444bld',
			'555bf'=>'555bld',
			'333mbf'=>'333mlt',
		);
		foreach ($events as $event=>$data) {
			$sheet->setCellValue($col . 2, "=SUM({$col}4:{$col}" . (count($registrations) + 4) . ')');
			$sheet->setCellValue($col . 3, isset($cubecompsEvents[$event]) ? $cubecompsEvents[$event] : $event);
			$sheet->getColumnDimension($col)->setWidth(5.5);
			$col++;
		}
		foreach ($registrations as $key=>$registration) {
			$user = $registration->user;
			$row = $key + 4;
			$sheet->setCellValue('A' . $row, $registration->number)
				->setCellValue('B' . $row, $user->getCompetitionName())
				->setCellValue('C' . $row, $user->country->name)
				->setCellValue('D' . $row, $user->wcaid)
				->setCellValue('E' . $row, $user->getWcaGender())
				->setCellValue('F' . $row, PHPExcel_Shared_Date::FormattedPHPToExcel(
					date('Y', $user->birthday),
					date('m', $user->birthday),
					date('d', $user->birthday)
				));
			$col = 'J';
			foreach ($events as $event=>$data) {
				if (in_array("$event", $registration->events)) {
					$sheet->setCellValue($col . $row, 1);
				}
				$col++;
			}
			if ($extra) {
				$col++;
				$fee = $registration->getTotalFee();
				if ($registration->isPaid()) {
					$fee .= Yii::t('common', ' (paid)');
				}
				$sheet->setCellValue($col . $row, $fee);
				$col++;
				$sheet->setCellValue($col . $row, $user->mobile);
				$col++;
				$sheet->setCellValue($col . $row, $user->email);
				$col++;
				$sheet->setCellValue($col . $row, $registration->comments);
			}
			if (!$registration->isAccepted()) {
				$sheet->getStyle("A{$row}:D{$row}")->applyFromArray(array(
					'fill'=>array(
						'type'=>PHPExcel_Style_Fill::FILL_SOLID,
						'color'=>array(
							'argb'=>'FFFFFF00',
						),
					),
		 		));
			}
		}
		$export->addExternalSheet($sheet);
		//各个项目
		foreach ($exportFormsts as $event=>$rounds) {
			$count = count($rounds);
			foreach ($rounds as $round=>$format) {
				if ($round == $count - 1) {
					$round = 'f';
				} else {
					$round++;
				}
				$sheet = $template->getSheetByName($format);
				if ($sheet === null) {
					continue;
				}
				$sheet = clone $sheet;
				$sheet->setTitle("{$event}-{$round}");
				$template->addSheet($sheet);
				$sheet->setCellValue('A1', Events::getFullEventName($event) . ' - ' . Rounds::getFullRoundName($round));
				if ($round == 1 || $count == 1) {
					$row = 5;
					foreach ($registrations as $registration) {
						if (!in_array("$event", $registration->events)) {
							continue;
						}
						$user = $registration->user;
						$sheet->setCellValue('B' . $row, $user->getCompetitionName())
							->setCellValue('C' . $row, $user->country->name)
							->setCellValue('D' . $row, $user->wcaid);
						if ($row > 5) {
							$formula = $sheet->getCell('A' . ($row - 1))->getValue();
							$formula = strtr($formula, array(
								'-4'=>'_temp_',
								$row - 1=>$row,
								$row - 2=>$row - 1,
								$row=>$row+1,
							));
							$formula = str_replace('_temp_', '-4', $formula);
							$sheet->setCellValue('A' . $row, $formula);
						}
						if (!$registration->isAccepted()) {
							$sheet->getStyle("A{$row}:D{$row}")->applyFromArray(array(
								'fill'=>array(
									'type'=>PHPExcel_Style_Fill::FILL_SOLID,
									'color'=>array(
										'argb'=>'FFFFFF00',
									),
								),
					 		));
						}
						$row++;
					}
				}
				$export->addExternalSheet($sheet);
			}
		}
		$this->exportToExcel($export, 'php://output', $competition->name, $xlsx);
	}

	public function exportScoreCard($competition, $all = false, $order = 'date') {
		$registrations = $this->getRegistrations($competition, $all, $order);
		$tempPath = Yii::app()->runtimePath;
		$templatePath = APP_PATH . '/public/static/score-card.xlsx';
		$scoreCard = PHPExcel_IOFactory::load($templatePath);
		$scoreCard->getProperties()
			->setCreator(Yii::app()->params->author)
			->setLastModifiedBy(Yii::app()->params->author)
			->setTitle($competition->wca_competition_id ?: $competition->name)
			->setSubject($competition->name);
		$sheet = $scoreCard->getSheet(0);
		//修复图片宽高及偏移
		$imageStyle = array(
			array(
				'width'=>72,
				'height'=>71,
				'offsetX'=>2,
				'offsetY'=>-13,
			),
			array(
				'width'=>72,
				'height'=>71,
				'offsetX'=>0,
				'offsetY'=>-14,
			),
		);
		$drawingCollection = $sheet->getDrawingCollection();
		foreach ($drawingCollection as $i=>$drawing) {
			$drawing->setWidthAndHeight(0, 0);
		}
		$rowHeights = array();
		$xfIndexes = array();
		$oneCardRow = 11;
		for ($row = 1; $row <= $oneCardRow; $row++) {
			$height = $sheet->getRowDimension($row)->getRowHeight();
			if ($height === -1) {
				$height = isset($rowHeights[$row - 1]) ? $rowHeights[$row - 1] - 1 : 10;
			}
			$rowHeights[$row] = $height;
			$xfIndexes[$row] = array();
			for ($col = 'A'; strcmp($col, 'K') != 0; $col++) {
				$xfIndexes[$row][$col] = $sheet->getCell($col . $row)->getXfIndex();
			}
		}
		//fix the height of last row
		$rowHeights[$oneCardRow] = 12.75;
		$staticCells = array(
			'A2', 'D2', 'F2',
			'A3', 'B3', 'C3', 'D3', 'E3', 'F3', 'G3', 'H3', 'I3', 'J3',
			'A4', 'B4', 'C4', 'D4', 'E4', 'F4', 'G4', 'H4', 'I4', 'J4',
			'A5', 'A6', 'A7', 'A8', 'A9',
			'A10',
		);
		$values = array();
		foreach ($staticCells as $cell) {
			$value = $sheet->getCell($cell)->getValue();
			$template = preg_replace('{\d+}', '{row}', $cell);
			$row = preg_replace('{[A-Z]}', '', $cell);
			$values[] = array(
				'value'=>$value,
				'template'=>$template,
				'row'=>$row,
			);
		}
		$i = 0;
		$count = 0;
		foreach ($registrations as $registration) {
			$user = $registration->user;
			$inChina = $user->country_id <= 4;
			foreach ($registration->events as $event) {
				if ($event === '333fm') {
					continue;
				}
				//merge cells
				//wcaid
				$sheet->mergeCells(sprintf('I%d:J%d', $i * $oneCardRow + 1, $i * $oneCardRow + 1));
				//event
				$sheet->mergeCells(sprintf('B%d:C%d', $i * $oneCardRow + 2, $i * $oneCardRow + 2));
				//name
				$sheet->mergeCells(sprintf('G%d:I%d', $i * $oneCardRow + 2, $i * $oneCardRow + 2));

				//调整各行高度及样式
				for ($j = 1; $j <= $oneCardRow; $j++) {
					$row = $i * $oneCardRow + $j;
					$sheet->getRowDimension($row)->setRowHeight($rowHeights[$j]);
					foreach ($xfIndexes[$j] as $col=>$xfIndex) {
						$sheet->getCell($col . $row)->setXfIndex($xfIndex);
					}
				}
				//填写固定内容
				foreach ($values as $value) {
					$row = $i * $oneCardRow + $value['row'];
					$cell = str_replace('{row}', $row, $value['template']);
					$sheet->setCellValue($cell, $value['value']);
				}
				//比赛名字
				$row = $i * $oneCardRow + 1;
				$sheet->setCellValue("A{$row}", sprintf('%s - Score Card', $inChina ? $competition->name_zh : $competition->name));
				$sheet->setCellValue("I{$row}", $user->wcaid);
				//项目、轮次、编号等
				$row = $i * $oneCardRow + 2;
				$eventName = Events::getFullEventName($event);
				$eventName = sprintf('%s %s', Yii::t('event', $eventName), $event);
				$sheet->setCellValue("B{$row}", $eventName);
				$sheet->setCellValue("E{$row}", '1st');
				$sheet->setCellValue("J{$row}", 'No.' . $registration->number);
				$sheet->setCellValue("G{$row}", $inChina && $user->name_zh ? $user->name_zh : $user->name);
				//8个图片
				$row = $i * $oneCardRow + 3;
				$col = 'D';
				for ($j = 0; $j < 4; $j++) {
					$drawing = new PHPExcelDrawing();
					$drawing->setImageIndex($drawingCollection[$j]->getImageIndex());
					$drawing->setWorksheet($sheet);
					$drawing->setPath($drawingCollection[$j]->getPath(), false);
					$drawing->setResizeProportional(false);
					$drawing->setCoordinates("{$col}{$row}");
					$drawing->setWidth($imageStyle[0]['width'])->setHeight($imageStyle[0]['height']);
					$drawing->setOffsetX($imageStyle[0]['offsetX'])->setOffsetY($imageStyle[0]['offsetY']);
					$col++;
				}
				$row = $i * $oneCardRow + 4;
				$col = 'D';
				for ($j = 4; $j < 8; $j++) {
					$drawing = new PHPExcelDrawing();
					$drawing->setImageIndex($drawingCollection[$j]->getImageIndex());
					$drawing->setWorksheet($sheet);
					$drawing->setPath($drawingCollection[$j]->getPath(), false);
					$drawing->setResizeProportional(false);
					$drawing->setCoordinates("{$col}{$row}");
					$drawing->setWidth($imageStyle[1]['width'])->setHeight($imageStyle[1]['height']);
					$drawing->setOffsetX($imageStyle[1]['offsetX'])->setOffsetY($imageStyle[1]['offsetY']);
					$col++;
				}
				$i++;
				//400张一个表
				if ($i == 400) {
					$path = $tempPath . '/' . $competition->name . ".$count.xlsx";
					$this->exportToExcel($scoreCard, $path);
					//释放内存
					$scoreCard->disconnectWorksheets();
					unset($scoreCard, $sheet);
					$count++;
					$i = 0;
					//新开excel
					$scoreCard = PHPExcel_IOFactory::load($templatePath);
					$scoreCard->getProperties()
						->setCreator(Yii::app()->params->author)
						->setLastModifiedBy(Yii::app()->params->author)
						->setTitle($competition->wca_competition_id ?: $competition->name)
						->setSubject($competition->name);
					$sheet = $scoreCard->getSheet(0);
					//修复图片宽高及偏移
					$drawingCollection = $sheet->getDrawingCollection();
					foreach ($drawingCollection as $drawing) {
						$drawing->setWidthAndHeight(0, 0);
					}
				}
			}
		}
		if ($count == 0) {
			//修复第四行高度
			$this->exportToExcel($scoreCard, 'php://output', $competition->name);
		} else {
			//压缩成zip
			$path = $tempPath . '/' . $competition->name . ".$count.xlsx";
			$this->exportToExcel($scoreCard, $path);
			//释放内存
			$scoreCard->disconnectWorksheets();
			unset($scoreCard, $sheet);
			$count++;
			$zip = new ZipArchive();
			$tempName = tempnam($tempPath, 'scoreCardTmp');
			if ($zip->open($tempName, ZipArchive::CREATE) !== true) {
				throw new CHttpException(500, '创建压缩文件失败');
			}
			$dir = 'score-card';
			$zip->addEmptyDir($dir);
			for ($i = 0; $i < $count; $i++) {
				$path = $tempPath . '/' . $competition->name . ".$i.xlsx";
				$zip->addFile($path, $dir. '/' . basename($path));
			}
			$zip->close();
			header('Content-Type: application/zip');
			header('Content-Disposition: attachment;filename="' . $competition->name . '.zip"');
			readfile($tempName);
			//删除临时文件
			for ($i = 0; $i < $count; $i++) {
				$path = $tempPath . '/' . $competition->name . ".$i.xlsx";
				unlink($path);
			}
			unlink($tempName);
			exit;
		}
	}

	private function getRegistrations($competition, $all = false, $order = 'date') {
		$attributes = array(
			'competition_id'=>$competition->id,
		);
		if (!$all) {
			$attributes['status'] = Registration::STATUS_ACCEPTED;
		}
		if (!in_array($order, array('date', 'user.name'))) {
			$order = 'date';
		}
		$registrations = Registration::model()->with(array(
			'user'=>array(
				'condition'=>'user.status=' . User::STATUS_NORMAL,
			),
			'user.country',
		))->findAllByAttributes($attributes, array(
			'order'=>'date',
		));
		//计算序号
		$number = 1;
		foreach ($registrations as $registration) {
			if ($registration->isAccepted()) {
				$registration->number = $number++;
			}
		}
		usort($registrations, function ($rA, $rB) use ($order) {
			if ($rA->number === $rB->number || ($rA->number !== null && $rB->number !== null)) {
				switch ($order) {
					case 'user.name':
						return strcmp($rA->user->getCompetitionName(), $rB->user->getCompetitionName());
					case 'date':
					default:
						return $rA->date - $rB->date;
				}
			}
			if ($rA->number === null) {
				return 1;
			}
			if ($rB->number === null) {
				return -1;
			}
			return 0;
		});
		return $registrations;
	}

	private function exportToExcel($excel, $path = 'php://output', $filename = 'CubingChina', $xlsx = true) {
		$download = $path === 'php://output';
		$excel->setActiveSheetIndex(0);
		Yii::app()->controller->setIsAjaxRequest(true);
		if ($xlsx) {
			$writer = PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
		} else {
			$writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
		}
		if ($download) {
			if ($xlsx) {
				header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
				header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
			} else {
				header('Content-Type: application/vnd.ms-excel');
				header('Content-Disposition: attachment;filename="' . $filename . '.xls"');
			}
		}
		$writer->setPreCalculateFormulas(false);
		$writer->save($path);
		if ($download) {
			exit;
		}
	}

	public function actionSendNotice() {
		$id = $this->iGet('id');
		$competition = Competition::model()->findByPk($id);
		if ($competition === null) {
			$this->redirect(Yii::app()->request->urlReferrer);
		}
		if ($this->user->isOrganizer() && !isset($competition->organizers[$this->user->id])) {
			Yii::app()->user->setFlash('danger', '权限不足！');
			$this->redirect(array('/board/registration/index'));
		}
		$competition->formatEvents();
		$registration = new Registration();
		$registration->unsetAttributes();
		$registration->competition_id = $id;
		$model = new SendNoticeForm();
		if (isset($_POST['SendNoticeForm'])) {
			$model->attributes = $_POST['SendNoticeForm'];
			if ($model->validate() && $model->send($competition)) {
				Yii::app()->user->setFlash('success', '发送成功！');
				$this->redirect(array('/board/registration/index', 'Registration'=>array('competition_id'=>$id)));
			}
		}
		$this->render('sendNotice', array(
			'model'=>$model,
			'competition'=>$competition,
			'registration'=>$registration,
		));
	}

	public function actionPreviewNotice() {
		$id = $this->iGet('id');
		$competition = Competition::model()->findByPk($id);
		if ($competition === null) {
			throw new CHttpException(404, '未知比赛ID');
		}
		if ($this->user->isOrganizer() && !isset($competition->organizers[$this->user->id])) {
			throw new CHttpException(403, '权限不足');
		}
		$competition->formatEvents();
		$registration = new Registration();
		$registration->unsetAttributes();
		$registration->competition_id = $id;
		$model = new SendNoticeForm();
		if (isset($_POST['SendNoticeForm'])) {
			$model->attributes = $_POST['SendNoticeForm'];
		}
		echo json_encode($model->getPreview($competition));
	}

	public function actionEdit() {
		$id = $this->iGet('id');
		$model = Registration::model()->findByPk($id);
		if ($model === null) {
			$this->redirect(Yii::app()->request->urlReferrer);
		}
		if ($this->user->isOrganizer() && $model->competition && !isset($model->competition->organizers[$this->user->id])) {
			Yii::app()->user->setFlash('danger', '权限不足！');
			$this->redirect(array('/board/registration/index'));
		}
		if (isset($_POST['Registration'])) {
			$model->attributes = $_POST['Registration'];
			if ($model->save()) {
				Yii::app()->user->setFlash('success', '更新报名信息成功');
				$this->redirect(array('/board/registration/index', 'Registration'=>array(
					'competition_id'=>$model->competition_id,
				)));
			}
		}
		$model->formatEvents();
		$model->competition->formatEvents();
		$this->render('edit', array(
			'model'=>$model,
		));
	}

	public function actionShow() {
		$id = $this->iGet('id');
		$model = Registration::model()->findByPk($id);
		if ($model === null) {
			$this->redirect(Yii::app()->request->urlReferrer);
		}
		$model->formatEvents();
		$model->status = Registration::STATUS_ACCEPTED;
		$model->save();
		Yii::app()->user->setFlash('success', '通过报名成功');
		$this->redirect(Yii::app()->request->urlReferrer);
	}

	public function actionHide() {
		$id = $this->iGet('id');
		$model = Registration::model()->findByPk($id);
		if ($model === null) {
			$this->redirect(Yii::app()->request->urlReferrer);
		}
		$model->status = Registration::STATUS_WAITING;
		$model->save();
		Yii::app()->user->setFlash('success', '取消报名成功');
		$this->redirect(Yii::app()->request->urlReferrer);
	}

	public function actionPaid() {
		$id = $this->iGet('id');
		$model = Registration::model()->findByPk($id);
		if ($model === null) {
			$this->redirect(Yii::app()->request->urlReferrer);
		}
		$model->formatEvents();
		$model->paid = Registration::PAID;
		$model->save();
		Yii::app()->user->setFlash('success', $model->user->name . '已付！');
		$this->redirect(Yii::app()->request->urlReferrer);
	}

	public function actionUnpaid() {
		$id = $this->iGet('id');
		$model = Registration::model()->findByPk($id);
		if ($model === null) {
			$this->redirect(Yii::app()->request->urlReferrer);
		}
		$model->paid = Registration::UNPAID;
		$model->save();
		Yii::app()->user->setFlash('success', $model->user->name . '未付！');
		$this->redirect(Yii::app()->request->urlReferrer);
	}

	public function actionToggle() {
		$id = $this->iRequest('id');
		$model = Registration::model()->findByPk($id);
		if ($model === null) {
			throw new CHttpException(404, 'Not found');
		}
		if ($this->user->isOrganizer() && $model->competition && !isset($model->competition->organizers[$this->user->id])) {
			throw new CHttpException(401, 'Unauthorized');
		}
		$attribute = $this->sRequest('attribute');
		$model->$attribute = 1 - $model->$attribute;
		if ($model->isAccepted()) {
			$model->total_fee = $model->getTotalFee(true);
		}
		$model->save();
		$this->ajaxOk(array(
			'value'=>$model->$attribute,
		));
	}
}
